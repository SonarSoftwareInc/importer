<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Leth\IPAddress\IP\NetworkAddress;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class SubnetImporter extends AccessesSonar
{
    private $supernets;

    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","subnet_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","subnet_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $this->getExistingSupernets();

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $lethSubnet = NetworkAddress::factory(trim($data[1]));
                foreach ($this->supernets as $supernet)
                {
                    if ($supernet['object']->encloses_subnet($lethSubnet))
                    {
                        array_push($data,$supernet['id']);
                        array_push($validData, $data);
                        continue;
                    }

                    array_push($data,"Subnet did not fit into any defined supernets.");
                    fputcsv($failureLog,$data);
                    $returnData['failures'] += 1;
                }

            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    $supernetID = array_pop($validDatum);
                    yield new Request("PATCH", $this->uri . "/api/v1/network/ipam/supernets/$supernetID/subnets", [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                        ]
                        , json_encode($this->buildPayload($validDatum)));
                }
            };

            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData)
                {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode > 201)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $line = $validData[$index];
                        array_push($line,$body);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                    else
                    {
                        $returnData['successes'] += 1;
                        fwrite($successLog,"Import succeeded for subnet {$validData[$index][0]}" . "\n");
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData)
                {
                    $response = $reason->getResponse();
                    $body = json_decode($response->getBody()->getContents());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    $line = $validData[$index];
                    array_push($line,$returnMessage);
                    fputcsv($failureLog,$line);
                    $returnData['failures'] += 1;
                }
            ]);

            $promise = $pool->promise();
            $promise->wait();
        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        fclose($failureLog);
        fclose($successLog);

        return $returnData;
    }

    /**
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1,2,3 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the subnet import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (!in_array($data[2],['customer','infrastructure','reserved','mixed']))
                {
                    throw new InvalidArgumentException("{$data[2]} is not a valid type on row $row.");
                }

                try {
                    NetworkAddress::factory($data[1]);
                }
                catch (Exception $e)
                {
                    throw new InvalidArgumentException("{$data[1]} is not a valid subnet on row $row. It should be in CIDR format (e.g. 1.2.3.0/24)");
                }

                if (!is_numeric($data[3]) || (int)$data[3] < 1)
                {
                    throw new InvalidArgumentException("{$data[3]} is not a valid network site ID on row $row. It must be numeric and 1 or greater.");
                }
            }
        }
        else
        {
            throw new InvalidArgumentException("Could not open import file.");
        }

        return;
    }

    /**
     * @param $data
     * @return array
     */
    private function buildPayload($data)
    {
        $request = [
            'name' => trim($data[0]),
            'subnet' => trim($data[1]),
            'type' => trim($data[2]),
            'network_site_id' => (int)trim($data[3]),
            'inline_devices' => explode(",",trim($data[4])),
        ];

        return $request;
    }

    /**
     * Retrieve all defined supernets from Sonar
     */
    private function getExistingSupernets()
    {
        $supernetArray = [];

        $page = 1;

        $supernets = $this->client->get($this->uri . "/api/v1/network/supernets&limit=100&page=$page",[
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
        ]);

        while ($supernets->paginator->current_page < $supernets->paginator->total_pages)
        {
            foreach ($supernets->data as $individualSupernet)
            {
                $lethSupernet = NetworkAddress::factory($individualSupernet->subnet);
                array_push($supernetArray,[
                    'id' => $individualSupernet->id,
                    'name' => $individualSupernet->name,
                    'object' => $lethSupernet,
                    'subnet' => $individualSupernet->subnet,
                ]);
            }

            $page++;
            $supernets = $this->client->get($this->uri . "/api/v1/network/supernets&limit=100&page=$page",[
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);
        }

        $this->supernets = $supernetArray;
    }
}
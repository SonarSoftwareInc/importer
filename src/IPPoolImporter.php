<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Leth\IPAddress\IP\Address;
use Leth\IPAddress\IP\NetworkAddress;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class IPPoolImporter extends AccessesSonar
{
    private $subnets;

    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $this->getExistingSubnets();

            $failureLogName = tempnam(getcwd() . "/log_output","ip_pool_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","ip_pool_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $lethStart = Address::factory(trim($data[1]));
                $lethEnd = Address::factory(trim($data[2]));
                $foundAFit = false;
                foreach ($this->subnets as $subnet)
                {
                    try {
                        if ($subnet['object']->encloses_address($lethStart) && $subnet['object']->encloses_address($lethEnd))
                        {
                            array_push($data,$subnet['id']);
                            array_push($data,$subnet['supernet_id']);
                            array_push($validData, $data);
                            $foundAFit = true;
                            break;
                        }
                    }
                    catch (Exception $e)
                    {
                        //
                    }
                }

                if ($foundAFit === false)
                {
                    array_push($data,"IP pool did not fit into any defined subnets.");
                    fputcsv($failureLog,$data);
                    $returnData['failures'] += 1;
                }
            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    $supernetID = array_pop($validDatum);
                    $subnetID = array_pop($validDatum);

                    yield new Request("POST", $this->uri . "/api/v1/network/ipam/supernets/$supernetID/subnets/$subnetID/ip_pools", [
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
                        fwrite($successLog,"Import succeeded for account ID {$validData[$index][0]}" . "\n");
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
     * @param $data
     * @return array
     */
    private function buildPayload($data)
    {
        return [
            'name' => trim($data[0]),
            'start' => trim($data[1]),
            'end' => trim($data[2]),
            'dhcp_servers' => trim($data[3]) ? explode(",",trim($data[3])) : null,
        ];
    }

    /**
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1,2 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the IP pool import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                try {
                    \Leth\IPAddress\IPv4\Address::factory($data[1]);
                }
                catch (Exception $e)
                {
                    throw new InvalidArgumentException("The start IP on row $row is not a valid IPv4 address.");
                }

                try {
                    \Leth\IPAddress\IPv4\Address::factory($data[2]);
                }
                catch (Exception $e)
                {
                    throw new InvalidArgumentException("The end IP on row $row is not a valid IPv4 address.");
                }

                if (trim($data[3]))
                {
                    $boom = explode(",",trim($data[3]));
                    foreach ($boom as $inner)
                    {
                        if ((int)$inner < 1)
                        {
                            throw new InvalidArgumentException("$inner is not a valid DHCP server ID.");
                        }
                    }
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
     * Retrieve all defined subnets from Sonar
     */
    private function getExistingSubnets()
    {
        $supernetIDs = [];
        $subnetArray = [];

        $page = 1;

        $supernets = $this->client->get($this->uri . "/api/v1/network/ipam/supernets?limit=100&page=$page",[
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
        ]);

        $supernets = json_decode($supernets->getBody()->getContents());

        while ($page <= $supernets->paginator->total_pages)
        {
            foreach ($supernets->data as $individualSupernet)
            {
                array_push($supernetIDs,$individualSupernet->id);
            }

            $page++;
            $supernets = $this->client->get($this->uri . "/api/v1/network/ipam/supernets?limit=100&page=$page",[
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);

            $supernets = json_decode($supernets->getBody()->getContents());
        }

        foreach ($supernetIDs as $supernetID)
        {
            $subnets = $this->client->get($this->uri . "/api/v1/network/ipam/supernets/$supernetID/subnets?limit=100&page=$page",[
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);

            $subnets = json_decode($subnets->getBody()->getContents());

            while ($page <= $subnets->paginator->total_pages)
            {
                foreach ($subnets->data as $individualSubnet)
                {
                    $lethSupernet = NetworkAddress::factory($individualSubnet->subnet);
                    array_push($subnetArray,[
                        'id' => $individualSubnet->id,
                        'name' => $individualSubnet->name,
                        'object' => $lethSupernet,
                        'subnet' => $individualSubnet->subnet,
                        'supernet_id' => $supernetID,
                    ]);
                }

                $page++;
                $subnets = $this->client->get($this->uri . "/api/v1/network/ipam/supernets/$supernetID/subnets?limit=100&page=$page",[
                    'headers' => [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                    ],
                    'auth' => [
                        $this->username,
                        $this->password,
                    ],
                ]);

                $subnets = json_decode($subnets->getBody()->getContents());
            }
        }

        $this->subnets = $subnetArray;
    }
}
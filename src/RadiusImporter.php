<?php

namespace SonarSoftware\Importer;

use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class RadiusImporter extends AccessesSonar
{
    protected $radiusAccountIDsByUsername = [];

    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output", "_radius_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output", "_radius_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                array_push($validData, $data);
            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/radius_accounts", [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                        ]
                        , json_encode($this->buildPayload($validDatum)));
                }
            };

            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, &$radiusAccountIDsByUsername)
                {
                    $statusCode = $response->getStatusCode();
                    $body = json_decode($response->getBody()->getContents());

                    if ($statusCode > 201)
                    {
                        $line = $validData[$index];
                        array_push($line,$body);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                    else
                    {
                        $this->radiusAccountIDsByUsername[$body->data->username] = $body->data->id;
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

            if (count($radiusAccountIDsByUsername) > 0)
            {
                //Associate IPs with usernames if required now.
                $ipRequests = function () use ($validData)
                {
                    foreach ($validData as $validDatum)
                    {
                        if (trim($validDatum[3]) && filter_var(trim($validDatum[3]),FILTER_VALIDATE_IP))
                        {
                            yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/ip_assignments", [
                                    'Content-Type' => 'application/json; charset=UTF8',
                                    'timeout' => 30,
                                    'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                                ]
                                , json_encode($this->buildIpPayload($validDatum)));
                        }
                    }
                };
            }

            $pool = new Pool($this->client, $ipRequests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData)
                {
                    $statusCode = $response->getStatusCode();
                    $body = json_decode($response->getBody()->getContents());

                    if ($statusCode > 201)
                    {
                        $line = $validData[$index];
                        array_push($line,$body);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                    else
                    {
                        $returnData['successes'] += 1;
                        fwrite($successLog,"IP assignment succeeded for account ID {$validData[$index][0]}" . "\n");
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
     * Validate all the data in the import file.
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1,2 ];

        $usernames = [];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                if (in_array(trim($data[1]),$usernames))
                {
                    throw new InvalidArgumentException(trim($data[1]) . " is entered as a username into the import file multiple times. Usernames must be unique.");
                }
                array_push($usernames,trim($data[1]));
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the RADIUS import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }
            }

            if (trim($data[3]))
            {
                if (!filter_var(trim($data[3]),FILTER_VALIDATE_IP))
                {
                    throw new InvalidArgumentException("In the RADIUS import, column number " . ($colNumber + 1) . " is not a valid IP ({$data[3]}).");
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
        return [
            'username' => trim($data[1]),
            'password' => trim($data[2]),
            'create_on_server' => trim($data[4]) == 1? true : false,
        ];
    }

    /**
     * @param $data
     * @return array
     */
    private function buildIpPayload($data)
    {
        return [
            'subnet' => trim($data[3]),
            'assigned_entity' => 'radius_accounts',
            'assigned_id' => $this->radiusAccountIDsByUsername[trim($data[1])],
        ];
    }
}
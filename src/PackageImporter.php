<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class PackageImporter extends AccessesSonar
{
    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","package_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","package_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/system/packages", [
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
                        fwrite($successLog,"Import succeeded for package {$validData[$index][0]}" . "\n");
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData)
                {
                    $response = $reason->getResponse();
                    if ($response)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $returnMessage = implode(", ",(array)$body->error->message);
                    }
                    else
                    {
                        $returnMessage = "No response returned from Sonar.";
                    }
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
        $payload = [
            'name' => trim($data[0]),
            'active' => true,
            'services' => [],
        ];

        if (trim($data[1]))
        {
            $payload['active'] = (bool)$data[1];
        }

        if (trim($data[2]))
        {
            $payload['services'] = array_filter(explode(",",$data[2]), function($var)
            {
                return (int)$var;
            });
        }

        return $payload;
    }

    /**
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,2 ];
        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the packages import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (trim($data[2]))
                {
                    $serviceIDs = explode(",",$data[2]);
                    if (count($serviceIDs) === 0)
                    {
                        throw new InvalidArgumentException("In the packages import, there are no service IDs on row $row.");
                    }
                    foreach ($serviceIDs as $serviceID)
                    {
                        if ((int)$serviceID != $serviceID || !is_numeric($serviceID) || (int)$serviceID < 1)
                        {
                            throw new InvalidArgumentException("In the packages import, the service ID $serviceID is invalid on row $row.");
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
}
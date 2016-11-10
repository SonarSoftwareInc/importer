<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AccountPackageImporter extends AccessesSonar
{
    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->loadPackageData();
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","account_package_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_package_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/packages", [
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
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the account package import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (!array_key_exists($data[1],$this->packages))
                {
                    throw new InvalidArgumentException("Package ID {$data[1]} does not exist.");
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
     * Load available packages
     */
    private function loadPackageData()
    {
        $packageArray = [];

        $response = $this->client->get($this->uri . "/api/v1/system/packages", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ]
        ]);

        $objResponse = json_decode($response->getBody());
        foreach ($objResponse->data as $datum)
        {
            $packageArray[$datum->id] = [
                'name' => $datum->name,
            ];
        }

        while ($objResponse->paginator->current_page != $objResponse->paginator->total_pages)
        {
            $response = $this->client->get($this->uri . "/api/v1/system/packages", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ]
            ]);

            $objResponse = json_decode($response->getBody());
            foreach ($objResponse->data as $datum)
            {
                $packageArray[$datum->id] = [
                    'name' => $datum->name,
                ];
            }
        }

        $this->packages = $packageArray;
    }

    private function addPackageToAccount($data)
    {
        $payload = [
            'package_id' => (int)trim($data[1]),
            'prorate' => false
        ];

        $accountID = (int)trim($data[0]);

        return $this->client->post($this->uri . "/api/v1/accounts/$accountID/packages", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'json' => $payload,
        ]);
    }
}
<?php

namespace SonarSoftware\Importer;

use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AccountDidImporter extends AccessesSonar
{
    private $services;

    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","account_did_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_did_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/dids", [
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

    private function buildPayload($data)
    {
        $payload = [
            'did' => trim($data[1]),
            'service_id' => trim($data[2]),
        ];

        return $payload;
    }

    /**
     * Load service data into a private var.
     */
    private function loadServiceData()
    {
        $serviceArray = [];

        $page = 1;

        $response = $this->client->get($this->uri . "/api/v1/system/services?page=$page", [
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
            if ($datum->type == "recurring" || $datum->type == "expiring")
            {
                $serviceArray[$datum->id] = [
                    'type' => $datum->type,
                    'application' => $datum->application,
                ];
            }
        }

        while ($objResponse->paginator->current_page != $objResponse->paginator->total_pages)
        {
            $page++;
            $response = $this->client->get($this->uri . "/api/v1/system/services?page=$page", [
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
                if ($datum->type == "recurring" || $datum->type == "expiring")
                {
                    $serviceArray[$datum->id] = [
                        'type' => $datum->type,
                        'application' => $datum->application,
                    ];
                }
            }
        }

        $this->services = $serviceArray;
    }

    private function validateImportFile($pathToImportFile)
    {
        $this->loadServiceData();
        $requiredColumns = [ 0,1,2 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the account DID import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (strlen($data[1]) != 10)
                {
                    throw new InvalidArgumentException("In the account DID import, row $row contains a " . strlen($data[1]) . " digit DID, and all DIDs must be 10 digits.");
                }

                if (!isset($this->services[$data[2]]))
                {
                    throw new InvalidArgumentException("In the account DID import, row $row references service ID {$data[2]} and that is not a valid service ID.");
                }

                if ($this->services[$data[2]]['type'] != "voice")
                {
                    throw new InvalidArgumentException("In the account DID import, row $row references service ID {$data[2]} and that is not a voice service.");
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
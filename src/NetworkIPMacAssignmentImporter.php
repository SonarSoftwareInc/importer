<?php

namespace SonarSoftware\Importer;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class NetworkIPMacAssignmentImporter extends AccessesSonar
{
    private $existingMacs = [];
    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output", "network_ip_mac_assignment_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","network_ip_mac_assignment_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $this->getExistingMacs();

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                array_push($validData, $data);
            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    yield new Request("POST", $this->uri . "/api/v1/network/network_sites/" . trim($validDatum[0]) . "/ip_assignments", [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                        ]
                        , json_encode($this->buildPayload($validDatum)));
                }
            };

            $client = new Client();

            $pool = new Pool($client, $requests(), [
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
                        fwrite($successLog,"Import succeeded for network site ID {$validData[$index][0]}" . "\n");
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
     * @throws InvalidArgumentException
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
                        throw new InvalidArgumentException("In the IP assignment import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                    if (!filter_var($row[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
                    {
                        throw new InvalidArgumentException("In the IP assignment import, column number " . ($colNumber+1) . " is not a valid IP.");
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
     * This builds up a list of all the MAC addresses defined in Sonar, and which customer has them.
     */
    private function getExistingMacs()
    {
        //First, we get the models and figure out which fields are MACs
        $macFieldIDs = $this->getMacFieldIDs();

        $response = $this->client->get($this->uri . "/api/v1/inventory/items?limit=100&page=1", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
        ]);

        $body = json_decode($response->getBody());
        $currentPage = $body->paginator->current_page;
        $totalPages = $body->paginator->total_pages;

        foreach ($body->data as $datum)
        {
            foreach ($datum->fields as $field)
            {
                if (in_array($field->field_id,$macFieldIDs))
                {
                    $this->existingMacs[$field->data] = [
                        'field_id' => $field->field_id,
                        'inventory_item_id' => $datum->id,
                    ];
                }
            }
        }

        while ($currentPage < $totalPages)
        {
            $response = $this->client->get($this->uri . "/api/v1/inventory/items?limit=100&page=" . ($currentPage+1), [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);

            $body = json_decode($response->getBody());
            $currentPage = $body->paginator->current_page;
            $totalPages = $body->paginator->total_pages;

            foreach ($body->data as $datum)
            {
                foreach ($datum->fields as $field)
                {
                    if (in_array($field->field_id,$macFieldIDs))
                    {
                        $this->existingMacs[$field->data] = [
                            'field_id' => $field->field_id,
                            'inventory_item_id' => $datum->id,
                        ];
                    }
                }
            }
        }
    }

    /**
     * @return array
     */
    private function getMacFieldIDs()
    {
        $macFieldIDs = [];
        $response = $this->client->get($this->uri . "/api/v1/inventory/models?limit=100&page=1", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
        ]);

        $body = json_decode($response->getBody());
        $currentPage = $body->paginator->current_page;
        $totalPages = $body->paginator->total_pages;

        foreach ($body->data as $datum)
        {
            $modelID = $datum->id;
            //If there are more than 100 fields, we could have problems here, but very unlikely to be the case..
            $fieldResponse = $this->client->get($this->uri . "/api/v1/inventory/models/$modelID/fields?limit=100&page=1", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);

            $fieldBody = json_decode($fieldResponse->getBody());
            foreach ($fieldBody->data as $fieldDatum)
            {
                if ($fieldDatum->type == "mac")
                {
                    array_push($macFieldIDs,$fieldDatum->id);
                }
            }
        }

        while ($currentPage < $totalPages)
        {
            $response = $this->client->get($this->uri . "/api/v1/inventory/items?limit=100&page=" . ($currentPage+1), [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);

            $body = json_decode($response->getBody());
            $currentPage = $body->paginator->current_page;
            $totalPages = $body->paginator->total_pages;

            foreach ($body->data as $datum)
            {
                $modelID = $datum->id;
                //If there are more than 100 fields, we could have problems here, but very unlikely to be the case..
                $fieldResponse = $this->client->get($this->uri . "/api/v1/inventory/models/$modelID/fields?limit=100&page=1", [
                    'headers' => [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                    ],
                    'auth' => [
                        $this->username,
                        $this->password,
                    ],
                ]);

                $fieldBody = json_decode($fieldResponse->getBody());
                foreach ($fieldBody->data as $fieldDatum)
                {
                    if ($fieldDatum->type == "mac")
                    {
                        array_push($macFieldIDs,$fieldDatum->id);
                    }
                }
            }
        }

        return $macFieldIDs;
    }

    /**
     * @param $row
     * @return array
     */
    private function buildPayload($row)
    {
        $payload = [];
        $payload['subnet'] = trim($row[1]);
        $mac = $this->formatMac(trim($row[2]));

        if (array_key_exists($mac,$this->existingMacs))
        {
            //This needs to be added to an inventory item
            $payload['assigned_entity'] = "inventory_items";
            $payload['assigned_id'] = (int)$this->existingMacs[$mac]['inventory_item_id'];
            $payload['inventory_item_field_id'] = (int)$this->existingMacs[$mac]['field_id'];
        }
        else
        {
            throw new InvalidArgumentException("The MAC address $mac does not exist in Sonar.");
        }

        if (array_key_exists(3,$row))
        {
            if (trim($row[3]) !== '')
            {
                $payload['description'] = trim($row[3]);
            }
        }

        if (array_key_exists(4, $row))
        {
            if (is_numeric($row[4]))
            {
                $payload['service_id'] = (int)trim($row[4]);
            }
        }

        return $payload;
    }

    /**
     * Format MAC to a consistent format
     * @param $mac
     * @return mixed
     */
    private function formatMac($mac)
    {
        $cleanMac = str_replace(" ","",$mac);
        $cleanMac = str_replace("-","",$cleanMac);
        $cleanMac = str_replace(":","",$cleanMac);
        $cleanMac = strtoupper($cleanMac);
        $macSplit = str_split($cleanMac,2);
        return implode(":",$macSplit);
    }

    /**
     * @param $row
     * @return bool
     */
    private function importIpAssignment($row)
    {
        $payload = $this->buildPayload($row);
        $response = $this->client->post($this->uri . "/api/v1/network/network_sites/" . trim($row[0]) . "/ip_assignments", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'json' => $payload
        ]);

        return true;
    }
}
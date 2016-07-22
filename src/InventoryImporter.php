<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;

class InventoryImporter
{
    private $uri;
    private $username;
    private $password;
    private $client;

    private $fieldNames = [];
    private $modelNames = [];

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        $dotenv = new \Dotenv\Dotenv(__DIR__);
        $dotenv->overload();
        $dotenv->required(
            [
                'URI',
                'USERNAME',
                'PASSWORD',
            ]
        );

        $this->uri = getenv("URI");
        $this->username = getenv("USERNAME");
        $this->password = getenv("PASSWORD");

        $this->client = new \GuzzleHttp\Client();
    }

    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);
            $this->loadModelData();

            if (!file_exists(__DIR__ . "/../log_output"))
            {
                mkdir(__DIR__ . "/../log_output");
            }

            $failureLogName = tempnam(__DIR__ . "/../log_output", "inventory_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","inventory_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $row++;
                try {
                    $this->importItem($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $parsedFailures = [];
                    if (is_object($body->error))
                    foreach ($body->error->message as $singleMessage)
                    {
                        if (is_object($singleMessage))
                        {
                            foreach ($singleMessage as $innerMessage)
                            {
                                array_push($parsedFailures,$innerMessage);
                            }
                        }
                        else
                        {
                            array_push($parsedFailures,$body->error->message);
                        }
                    }
                    $returnMessage = implode(", ",$parsedFailures);
                    fwrite($failureLog,"Row $row failed: $returnMessage\n");
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    fwrite($failureLog,"Row $row failed: {$e->getMessage()}\n");
                    $returnData['failures'] += 1;
                    continue;
                }
                
                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for ID " . trim($data[0]) . "\n");
            }
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
     * Sheet must have an even number of columns, one for the field name, and one for the value.
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
                        throw new InvalidArgumentException("In the inventory import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }
                if ((count($data) % 2) !== 0)
                {
                    throw new InvalidArgumentException("There is an odd number of columns on row " . ($row+1) . ", which is not valid.");
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
     * Load model data
     */
    private function loadModelData()
    {
        $response = $this->client->get($this->uri . "/api/v1/inventory/models?limit=100&page=1", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ]
        ]);

        $body = json_decode($response->getBody());
        foreach ($body->data as $datum)
        {
            $this->storeModelDataAndGetFields($datum);
        }
        $currentPage = $body->paginator->current_page;
        $totalPages = $body->paginator->total_pages;

        while ($currentPage < $totalPages)
        {
            $nextPage = $currentPage+1;
            $response = $this->client->get($this->uri . "/api/v1/inventory/models?limit=100&page=$nextPage", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ]
            ]);

            $body = json_decode($response->getBody());
            foreach ($body->data as $datum)
            {
                $this->storeModelDataAndGetFields($datum);
            }
            $currentPage = $body->paginator->current_page;
            $totalPages = $body->paginator->total_pages;
        }
    }

    /**
     * Store the model data obtained from the API, and get field data
     * @param $datum
     */
    private function storeModelDataAndGetFields($datum)
    {
        $this->modelNames[strtolower(trim($datum->name))] = (int)trim($datum->id);
        $response = $this->client->get($this->uri . "/api/v1/inventory/models/{$datum->id}/fields", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ]
        ]);

        $body = json_decode($response->getBody());
        foreach ($body->data as $fieldDatum)
        {
            $this->fieldNames[$datum->id][strtolower(trim($fieldDatum->name))] = (int)trim($fieldDatum->id);
        }
    }

    /**
     * Build the API payload
     * @param $row
     * @return array
     */
    private function buildPayload($row)
    {
        $item = [];

        if (!array_key_exists(strtolower(trim($row[2])),$this->modelNames))
        {
            throw new InvalidArgumentException("The model " . trim($row[2]) . " does not exist in Sonar.");
        }

        $item["assignee_id"] = $row[0];
        $item["assignee_type"] = $row[1];
        $item["model_id"] = $this->modelNames[strtolower(trim($row[2]))];
        if (array_key_exists(3,$row))
        {
            if (in_array($row[3],['new','used']))
            {
                $item['condition'] = strtolower(trim($row[3]));
            }
        }
        if (array_key_exists(4,$row))
        {
            if (is_numeric($row[4]))
            {
                $item['quantity'] = (int)trim($row[4]);
            }
        }
        if (array_key_exists(5,$row))
        {
            if (is_numeric($row[5]))
            {
                $item['purchase_price'] = (float)trim($row[5]);
            }
        }

        $fields = [];
        $individualList = [];

        for ($i = 6; $i <= count($row); $i++)
        {
            if (trim($row[$i]) == '') {
                continue;
            }
            if ($i % 2 === 0)
            {
                //Field Name
                $name = $row[$i];
                $value = $row[$i+1];
                if (!array_key_exists(strtolower(trim($name)),$this->fieldNames[$this->modelNames[strtolower(trim($row[2]))]]))
                {
                    throw new InvalidArgumentException("The field " . trim($name) . " does not exist for model " . trim($row[2]));
                }
                $fields[$this->fieldNames[$this->modelNames[strtolower(trim($row[2]))]][strtolower(trim($name))]] = trim($value);
            }
        }

        $individualList[] = ["fields" => $fields];
        $item['individualList'] = $individualList;

        return $item;
    }

    /**
     * Import the inventory item
     * @param $row
     * @return bool
     * @internal param $handle
     */
    private function importItem($row)
    {
        $payload = $this->buildPayload($row);
        $response = $this->client->post($this->uri . "/api/v1/inventory/items", [
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

        return true;
    }
}
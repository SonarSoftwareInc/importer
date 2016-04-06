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
        $dotenv->load();
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
                    $formattedItem = $this->formatRow($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
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

                array_push($inventoryItemsToImport, $formattedItem);

                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for ID " . trim($data[0]) . "\n");
            }
        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        $json = [];
        $json["assignee_id"] = (int)trim($assigneeID);
        $json["assignee_type"] = trim($assigneeType);
        $json["model_id"] = trim($modelID);
        $condition = in_array($condition,['new','used']) ? $condition : 'new';
        $json['condition'] = strtolower(trim($condition));
        $json['quantity'] = (int)trim($quantity);
        $json['purchase_price'] = (float)trim($purchasePrice);

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

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                if ((count($data) % 2) !== 0)
                {
                    throw new InvalidArgumentException("There is an odd number of columns on row " . ($row+1) . ".");
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

        while ($currentPage <= $totalPages)
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
            $this->fieldNames[strtolower(trim($fieldDatum->name))] = (int)trim($fieldDatum->id);
        }
    }

    /**
     * Build the representation of the inventory item to be submitted to the API
     * @param $row
     * @return array
     * @internal param $handle
     */
    private function formatRow($row)
    {
        $item = [];

        if (!array_key_exists(strtolower(trim($row[2])),$this->modelIDsByName))
        {
            throw new InvalidArgumentException("The model " . trim($row[2]) . " does not exist in Sonar.");
        }

        $item["assignee_id"] = $row[0];
        $item["assignee_type"] = $row[1];
        $item["model_id"] = $this->modelIDsByName[strtolower(trim($row[2]))];
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

        $individualList = [];

        for ($i = 6; $i <= count($row); $i++)
        {
            if ($i % 2)
            {
                //Field Name
            }
            else
            {
                //Field Value
            }
        }

        return $item;
    }
}
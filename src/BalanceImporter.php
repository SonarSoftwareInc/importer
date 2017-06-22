<?php

namespace SonarSoftware\Importer;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use SonarSoftware\Importer\Extenders\AccessesSonar;
use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class BalanceImporter extends AccessesSonar
{
    private $debitAdjustmentID;
    private $creditAdjustmentID;

    public function __construct($debitAdjustmentID, $creditAdjustmentID)
    {
        parent::__construct();
        $this->debitAdjustmentID = $debitAdjustmentID;
        $this->creditAdjustmentID = $creditAdjustmentID;
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
            $this->validateServices();

            $failureLogName = tempnam(getcwd() . "/log_output","balance_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","balance_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                try {
                    $this->returnServiceID($data[1]);
                    array_push($validData,$data);
                }
                catch (InvalidArgumentException $e)
                {
                    continue;
                }
            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    yield new Request("POST",$this->uri . "/api/v1/accounts/" . (int)$validDatum[0] . "/services", [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                        'Authorization' => 'Basic '. base64_encode($this->username.':'.$this->password),
                    ]
                    ,json_encode([
                        'service_id' => $this->returnServiceID($validDatum[1]),
                        'prorate' => false,
                        'amount' => abs($validDatum[1]),
                    ]));
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
     * Validate all the data in the import file.
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
                        throw new InvalidArgumentException("In the balance update import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                    if (!is_numeric($data[$colNumber]))
                    {
                        throw new InvalidArgumentException("In the balance update import, column number " . ($colNumber + 1) . " is not numeric on row $row.");
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
     * Add a prior balance onto the account
     * @param $balance
     * @return bool
     * @internal param $data
     */
    public function returnServiceID($balance)
    {
        $priorBalance = number_format(trim((float)$balance),2,".","");
        if ($priorBalance == 0)
        {
            throw new InvalidArgumentException("Can't import a zero balance.");
        }

        if ($priorBalance > 0) {
            $serviceID = $this->debitAdjustmentID;
        } else {
            $serviceID = $this->creditAdjustmentID;
        }

        return $serviceID;
    }

    /**
     * Validate that the service IDs are valid debit/credit adjustment services.
     */
    private function validateServices()
    {
        try {
            $response = $this->client->get($this->uri . "/api/v1/system/services/" . (int)$this->debitAdjustmentID, [
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
        catch (ClientException $e)
        {
            throw new InvalidArgumentException("$this->debitAdjustmentID is not a valid service ID.");
        }

        $objResponse = json_decode($response->getBody());
        if ($objResponse->data->type != "adjustment" || $objResponse->data->application != "debit")
        {
            throw new InvalidArgumentException("$this->debitAdjustmentID is not a valid debit adjustment service.");
        }

        try {
            $response = $this->client->get($this->uri . "/api/v1/system/services/" . (int)$this->creditAdjustmentID, [
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
        catch (ClientException $e)
        {
            throw new InvalidArgumentException("$this->creditAdjustmentID is not a valid service ID.");
        }

        $objResponse = json_decode($response->getBody());
        if ($objResponse->data->type != "adjustment" || $objResponse->data->application != "credit")
        {
            throw new InvalidArgumentException("$this->creditAdjustmentID is not a valid credit adjustment service.");
        }

        return;
    }
}
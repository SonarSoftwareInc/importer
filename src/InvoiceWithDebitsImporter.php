<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;
use RuntimeException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class InvoiceWithDebitsImporter extends AccessesSonar
{
    /**
     * @param $pathToImportFile
     * @param $debitAdjustmentServiceID
     * @return array
     */
    public function import($pathToImportFile, $debitAdjustmentServiceID)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateServiceID($debitAdjustmentServiceID);
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","invoice_generation_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","invoice_generation_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $payload = $this->buildInitialPayload($data, $debitAdjustmentServiceID);
                if (count($payload) === 0) {
                    continue;
                }

                array_push($validData, $data);
            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/services", [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                        ]
                        , json_encode($validDatum));
                }
            };

            $invoiceGenerationRequests = [];

            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, $invoiceGenerationRequests)
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
                        $line = $validData[$index];
                        $invoiceGenerationRequests[$line['account_id']] = true;
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

        foreach ($validData as $validDatum)
        {
            if (isset($invoiceGenerationRequests[$validDatum[0]]))
            {
                //Bundle the created debit up into an invoice
                $requests = function () use ($validData, $debitAdjustmentServiceID, $returnData, $failureLog)
                {
                    foreach ($validData as $validDatum)
                    {
                        try {
                            $requestBody = $this->buildInvoicePayload($validDatum, $debitAdjustmentServiceID);
                        }
                        catch (Exception $e)
                        {
                            array_push($validData,$e->getMessage());
                            fputcsv($failureLog,$validData);
                            $returnData['failures'] += 1;
                            continue;
                        }

                        yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/invoices", [
                                'Content-Type' => 'application/json; charset=UTF8',
                                'timeout' => 30,
                                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                            ]
                            , json_encode($requestBody);
                    }
                };

                $pool = new Pool($this->client, $requests(), [
                    'concurrency' => 10,
                    'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, $invoiceGenerationRequests)
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
                            $line = $validData[$index];
                            $invoiceGenerationRequests[$line['account_id']] = true;
                            $returnData['successes'] += 1;
                            fwrite($successLog,"Invoice generation succeeded for account ID {$validData[$index][0]}" . "\n");
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
        }

        fclose($failureLog);
        fclose($successLog);

        return $returnData;
    }

    /**
     * @param $debitAdjustmentID
     */
    private function validateServiceID($debitAdjustmentID)
    {
        $client = new Client();
        try {
            $response = $client->get($this->uri . "/api/v1/system/services/$debitAdjustmentID", [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            ]);
        }
        catch (Exception $e)
        {
            throw new InvalidArgumentException($debitAdjustmentID . " is not a valid debit adjustment service.");
        }

        $json = json_decode($response->getBody()->getContents());

        if ($json->data->type !== "adjustment" || $json->data->application !== "debit")
        {
            throw new InvalidArgumentException($debitAdjustmentID . " is not a valid debit adjustment service.");
        }
    }

    /**
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        //account ID, amount, invoice date, due date
        $requiredColumns = [ 0,1,2,3 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the invoice generation import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (trim($data[1]))
                {
                    if ((float)$data[1] < 0.01)
                    {
                        throw new InvalidArgumentException("In the invoice generation import, column number " . ($colNumber + 1) . " is less than 0.01 on row $row.");
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
     * @param $data
     * @return array
     */
    private function buildInitialPayload($data, $debitServiceID)
    {
        return [
            'account_id' => $data[0],
            'service_id' => $debitServiceID,
            'amount' => $data[1],
        ];
    }

    /**
     * @param $validDatum
     * @param $adjustmentServiceID
     * @return array
     */
    private function buildInvoicePayload($validDatum, $adjustmentServiceID)
    {
        $debitID = null;
        $client = new Client();
        $response = $client->get($this->uri . "/api/v1/accounts/" . $validDatum[0] . "/transactions/debits",[
            'Content-Type' => 'application/json; charset=UTF8',
            'timeout' => 30,
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
        ]);

        $debits = json_decode($response->getBody()->getContents());
        foreach ($debits as $debit)
        {
            if ($debit->amount == $validDatum[1] && $debit->service_id == $adjustmentServiceID)
            {
                $debitID = $debit->id;
            }
            break;
        }

        if ($debitID === null)
        {
            throw new RuntimeException("Couldn't find debit for account ID {$validDatum[0]}");
        }

        return [
            'account_id' => $validDatum[0],
            'debits' => [
                $debitID,
            ],
            'due_date' => $validDatum[3],
            'date' => $validDatum[2],
        ];
    }
}
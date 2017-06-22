<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class UntokenizedCreditCardImporter extends AccessesSonar
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

            $failureLogName = tempnam(getcwd() . "/log_output","untokenized_card_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","untokenized_card_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/payment_methods", [
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
        $requiredColumns = [ 0,1,2,3,4,5,6,7,8,9,10 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the untokenized credit card import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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
    private function buildPayload($data)
    {
        return [
            'type' => 'credit card',
            'expiration_month' => trim($data[1]),
            'expiration_year' => strlen(trim($data[2])) > 2 ? trim($data[2]) : "20" . trim($data[2]),
            'account_number' => (int)trim($data[3]),
            'name_on_account' => trim($data[4]),
            'auto' => (boolean)$data[5],
            'line1' => trim($data[6]),
            'city' => trim($data[7]),
            'state' => trim($data[8]),
            'zip' => trim($data[9]),
            'country' => trim($data[10]),
        ];
    }

    /**
     * @param $data
     * @return mixed
     */
    private function createNewCreditCard($data)
    {
        $payload = $this->buildPayload($data);
        $accountID = (int)trim($data[0]);

        return $this->client->post($this->uri . "/api/v1/accounts/$accountID/payment_methods", [
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

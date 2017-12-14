<?php

namespace SonarSoftware\Importer;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use SonarSoftware\Importer\Extenders\AccessesSonar;
use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class AccountNextBillDateImporter extends AccessesSonar
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

            $failureLogName = tempnam(getcwd() . "/log_output","account_next_bill_date_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_next_bill_date_successes");
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
                    yield new Request("POST",$this->uri . "/api/v1/accounts/" . (int)$validDatum[0], [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                        'Authorization' => 'Basic '. base64_encode($this->username.':'.$this->password),
                    ]
                    ,json_encode([
                        'next_bill_date' => $validDatum[1],
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
                        fwrite($successLog,"Update succeeded for account ID {$validData[$index][0]}" . "\n");
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
                        throw new InvalidArgumentException("In the account next bill date update, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (!is_numeric($data[0]) == '') {
                    throw new InvalidArgumentException("In the account next bill date update, column number " . ($colNumber + 1) . " must be an integer on $row.");
                }

                try {
                    $carbon = new Carbon($data[1]);
                }
                catch (Exception $e)
                {
                    throw new InvalidArgumentException("In the account next bill date update, column number 2 must be a valid date in YYYY-MM-DD format on $row.");
                }

                $now = Carbon::now();
                if ($now->gte($carbon))
                {
                    throw new InvalidArgumentException("In the account next bill date update, column number 2 must be a date in the future on $row.");
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
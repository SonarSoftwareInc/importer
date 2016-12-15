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

class AccountIpAssignmentImporter extends AccessesSonar
{
    private $existingMacs = [];

    private $fieldNames = [];
    private $modelNames = [];

    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output", "account_ip_assignment_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_ip_assignment_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/ip_assignments", [
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
     * @throws InvalidArgumentException
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
     * @param $row
     * @return array
     */
    private function buildPayload($row)
    {
        $payload = [];
        $payload['subnet'] = trim($row[1]);

        if (array_key_exists(2,$row))
        {
            if (trim($row[2]) !== '')
            {
                $payload['description'] = trim($row[3]);
            }
        }

        if (array_key_exists(3, $row))
        {
            if (is_numeric($row[3]))
            {
                $payload['service_id'] = (int)trim($row[4]);
            }
        }

        return $payload;
    }
}
<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class ServiceImporter extends AccessesSonar
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

            $failureLogName = tempnam(getcwd() . "/log_output","service_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","service_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/system/services", [
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
                        fwrite($successLog,"Import succeeded for service {$validData[$index][0]}" . "\n");
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
     * @param $data
     * @return array
     */
    private function buildPayload($data)
    {
        $payload = [
            'active' => true,
            'name' => trim($data[0]),
            'type' => trim(strtolower($data[1])),
            'application' => trim(strtolower($data[2])),
            'amount' => (float)trim($data[3]),
            'data_service' => (bool)trim($data[6]),
        ];

        if (trim($data[4]))
        {
            $payload['times_to_run'] = (int)trim($data[4]);
        }

        if (trim($data[5]))
        {
            $payload['taxes'] = explode(",",trim($data[5]));
        }

        if (trim($data[7]))
        {
            $payload['download_in_kilobits'] = (int)trim($data[7]);
        }

        if (trim($data[8]))
        {
            $payload['upload_in_kilobits'] = (int)trim($data[8]);
        }

        if (trim($data[9]))
        {
            $payload['technology_code'] = (int)trim($data[9]);
        }

        if (trim($data[10]))
        {
            $payload['usage_based_billing_policy_id'] = (int)trim($data[10]);
        }

        if (trim($data[11]))
        {
            $payload['general_ledger_code_id'] = (int)trim($data[11]);
        }

        if (trim($data[12]))
        {
            $payload['tax_exemption_amount'] = (float)trim($data[12]);
        }

        return $payload;
    }

    /**
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1,2,3,6 ];
        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the account billing parameters import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (trim($data[1]))
                {
                    if (!in_array(trim($data[1]),['one time','recurring','expiring']))
                    {
                        throw new InvalidArgumentException(trim($data[1]) . " is not a valid service type.");
                    }
                }

                if (trim($data[2]))
                {
                    if (!in_array(trim($data[2]),['credit','debit']))
                    {
                        throw new InvalidArgumentException(trim($data[2]) . " is not a valid application.");
                    }
                }

                if (trim($data[3]))
                {
                    if (!is_numeric(trim($data[3])) || trim($data[3]) < 0)
                    {
                        throw new InvalidArgumentException(trim($data[3]) . " is not a valid service amount.");
                    }
                }

                if (trim($data[4]))
                {
                    if (!is_numeric(trim($data[4])) || trim($data[4]) < 1)
                    {
                        throw new InvalidArgumentException(trim($data[4]) . " is not a valid number of times to run.");
                    }
                }

                if (trim($data[5]))
                {
                    $taxes = explode(",",trim($data[5]));
                    foreach ($taxes as $tax)
                    {
                        if (!is_numeric($tax) || $tax < 1)
                        {
                            throw new InvalidArgumentException("$tax is not a valid tax ID.");
                        }
                    }
                }

                if (trim($data[9]))
                {
                    if (!in_array(trim($data[9]),[0,10,20,30,40,50,60,70,90]))
                    {
                        throw new InvalidArgumentException(trim($data[9]) . " is not a valid technology code.");
                    }
                }

                if (trim($data[10]))
                {
                    if (!is_numeric($data[10]) || $data[10] < 1)
                    {
                        throw new InvalidArgumentException($data[10] . " is not a valid general ledger code ID.");
                    }
                }

                if (trim($data[11]))
                {
                    if (!is_numeric(trim($data[11])) || trim($data[11]) < 0)
                    {
                        throw new InvalidArgumentException(trim($data[11]) . " is not a valid tax exemption amount.");
                    }
                }

                if ((bool)trim($data[6]) === true)
                {
                    if (trim($data[7]))
                    {
                        if (!is_numeric(trim($data[7])) || trim($data[7]) < 9)
                        {
                            throw new InvalidArgumentException(trim($data[7]) . " is not a valid download in kilobits, it must be numeric and greater than or equal to 8.");
                        }
                    }

                    if (trim($data[8]))
                    {
                        if (!is_numeric(trim($data[8])) || trim($data[8]) < 9)
                        {
                            throw new InvalidArgumentException(trim($data[8]) . " is not a valid upload in kilobits, it must be numeric and greater than or equal to 8.");
                        }
                    }

                    if (trim($data[9]))
                    {
                        if (!is_numeric($data[9]) || $data[9] < 1)
                        {
                            throw new InvalidArgumentException($data[9] . " is not a valid usage based billing policy ID.");
                        }
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
}
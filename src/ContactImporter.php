<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class ContactImporter extends AccessesSonar
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

            $failureLogName = tempnam(getcwd() . "/log_output","contact_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","contact_import_successes");
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
                    yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/contacts", [
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
                        throw new InvalidArgumentException("In the contact import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }

                    if ((trim($data[10]) && !trim($data[11])) || !trim($data[10]) && trim($data[11]))
                    {
                        throw new InvalidArgumentException("In the contact import, row $row has either a username or a password, but not both. If one is supplied, the other must be also.");
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
        $payload = [
            'id' => (int)trim($data[0]),
            'name' => (string)trim($data[1]),
        ];

        if (trim($data[2]))
        {
            $payload['role'] = trim($data[2]);
        }

        if (trim($data[3]))
        {
            $payload['email_address'] = trim($data[3]);
        }

        $phoneNumbers = [];
        if (trim($data[4]))
        {
            $phoneNumbers['work'] = [
                'number' => trim($data[4]),
                'extension' => trim($data[5]),
            ];
        }
        if (trim($data[6]))
        {
            $phoneNumbers['home'] = [
                'number' => trim($data[6]),
                'extension' => null,
            ];
        }
        if (trim($data[7]))
        {
            $phoneNumbers['mobile'] = [
                'number' => trim($data[7]),
                'extension' => null,
            ];
        }
        if (trim($data[8]))
        {
            $phoneNumbers['fax'] = [
                'number' => trim($data[8]),
                'extension' => null,
            ];
        }

        if (trim($data[9]))
        {
            $payload['email_message_categories'] = explode(",",trim($data[9]));
        }
        else
        {
            $payload['email_message_categories'] = [];
        }

        if (count($phoneNumbers) > 0)
        {
            $payload['phone_numbers'] = $phoneNumbers;
        }

        if (trim($data[10]))
        {
            $payload['username'] = trim($data[10]);
            $payload['password'] = trim($data[11]);
        }

        $payload['primary'] = false;

        return $payload;
    }

}
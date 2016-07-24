<?php

namespace SonarSoftware\Importer;

use InvalidArgumentException;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class NetworkSiteIpAssignmentImporter extends AccessesSonar
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

            $failureLogName = tempnam(getcwd() . "/log_output", "network_site_ip_assignment_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","network_site_ip_assignment_import_successes");
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
                    $this->importIpAssignment($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $parsedFailures = [];
                    if (is_array($body->error->message))
                    {
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
                                array_push($parsedFailures,$singleMessage);
                            }
                        }
                        $returnMessage = implode(", ",$parsedFailures);
                        fwrite($failureLog,"Row $row failed: $returnMessage\n");
                    }
                    else
                    {
                        $returnMessage = implode(", ",(array)$body->error->message);
                        fwrite($failureLog,"Row $row failed: $returnMessage\n");
                    }
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
                        throw new InvalidArgumentException("In the network site IP assignment import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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
        $payload['assigned_entity'] = "network_sites";

        if (array_key_exists(2,$row))
        {
            if (trim($row[2]) !== '')
            {
                $payload['description'] = trim($row[2]);
            }
        }
        
        return $payload;
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
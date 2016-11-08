<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Exception\ClientException;
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

            $row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $row++;
                try {
                    $this->createService($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    array_push($data,$returnMessage);
                    fputcsv($failureLog,$data);
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    array_push($data,$e->getMessage());
                    fputcsv($failureLog,$data);
                    $returnData['failures'] += 1;
                    continue;
                }

                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for service " . trim($data[1]) . "\n");
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
}
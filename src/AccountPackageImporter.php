<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AccountPackageImporter extends AccessesSonar
{
    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->loadPackageData();
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","account_package_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_package_import_successes");
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
                    $this->addPackageToAccount($data);
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
                fwrite($successLog,"Row $row succeeded for account ID " . trim($data[0]) . "\n");
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
                        throw new InvalidArgumentException("In the account package import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (!array_key_exists($data[1],$this->packages))
                {
                    throw new InvalidArgumentException("Package ID {$data[1]} does not exist.");
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
     * Load available packages
     */
    private function loadPackageData()
    {
        $packageArray = [];

        $response = $this->client->get($this->uri . "/api/v1/system/packages", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ]
        ]);

        $objResponse = json_decode($response->getBody());
        foreach ($objResponse->data as $datum)
        {
            $packageArray[$datum->id] = [
                'name' => $datum->name,
            ];
        }

        while ($objResponse->paginator->current_page != $objResponse->paginator->total_pages)
        {
            $response = $this->client->get($this->uri . "/api/v1/system/packages", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ]
            ]);

            $objResponse = json_decode($response->getBody());
            foreach ($objResponse->data as $datum)
            {
                $packageArray[$datum->id] = [
                    'name' => $datum->name,
                ];
            }
        }

        $this->packages = $packageArray;
    }

    private function addPackageToAccount($data)
    {
        $payload = [
            'package_id' => (int)trim($data[1]),
            'prorate' => false
        ];

        $accountID = (int)trim($data[0]);

        return $this->client->post($this->uri . "/api/v1/accounts/$accountID/packages", [
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
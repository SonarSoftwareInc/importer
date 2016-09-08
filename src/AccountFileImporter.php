<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AccountFileImporter extends AccessesSonar
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

            $failureLogName = tempnam(getcwd() . "/log_output","account_files_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_files_import_successes");
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
                    $this->uploadFile($data);
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
                        throw new InvalidArgumentException("In the account file import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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
     * @param $filePath
     * @param $data
     * @return array
     */
    private function buildPayload($filePath, $data)
    {
        $boom = explode("/",$filePath);

        $payload = [
            'filename' => $boom[count($boom)-1],
            'base64_data' => base64_encode(file_get_contents($filePath)),
        ];

        if (trim($data[2]))
        {
            $payload['description'] = trim($data[2]);
        }

        return $payload;
    }

    /**
     * @param $data
     */
    private function uploadFile($data)
    {
        $accountID = trim($data[0]);
        $file = trim($data[1]);
        if (!is_file($file))
        {
            if (is_dir($file))
            {
                $fileList = array_diff(scandir($file), array('..', '.'));
                foreach ($fileList as $fileName)
                {
                    $this->client->post($this->uri . "/api/v1/accounts/$accountID/files", [
                        'headers' => [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                        ],
                        'auth' => [
                            $this->username,
                            $this->password,
                        ],
                        'json' => $this->buildPayload($file . "/" . $fileName, $data),
                    ]);
                }
            }
            else
            {
                throw new InvalidArgumentException("$file is not a valid file or directory.");
            }

        }
        else
        {
            $this->client->post($this->uri . "/api/v1/accounts/$accountID/files", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
                'json' => $this->buildPayload($file, $data),
            ]);
        }
    }
}
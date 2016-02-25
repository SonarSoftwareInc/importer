<?php
namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class ContactImporter
{
    private $uri;
    private $username;
    private $password;
    private $client;

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        $dotenv = new \Dotenv\Dotenv(__DIR__);
        $dotenv->load();
        $dotenv->required(
            [
                'URI',
                'USERNAME',
                'PASSWORD',
            ]
        );

        $this->uri = getenv("URI");
        $this->username = getenv("USERNAME");
        $this->password = getenv("PASSWORD");

        $this->client = new \GuzzleHttp\Client();
    }

    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            if (!file_exists(__DIR__ . "/../log_output"))
            {
                mkdir(__DIR__ . "/../log_output");
            }

            $failureLogName = tempnam(__DIR__ . "/../log_output","contact_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","contact_import_successes");
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
                    $this->createContact($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    fwrite($failureLog,"Row $row failed: $returnMessage");
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    fwrite($failureLog,"Row $row failed: {$e->getMessage()}");
                    $returnData['failures'] += 1;
                    continue;
                }

                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for account ID " . trim($data[0]));
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
                        throw new InvalidArgumentException("In the contact import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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

        $payload['primary'] = false;

        return $payload;
    }

    /**
     * @param $data
     * @return mixed
     */
    private function createContact($data)
    {
        $payload = $this->buildPayload($data);
        $accountID = (int)trim($data[0]);

        return $this->client->post($this->uri . "/api/v1/accounts/$accountID/contacts", [
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
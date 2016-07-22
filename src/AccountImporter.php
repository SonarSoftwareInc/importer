<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;

class AccountImporter
{
    private $uri;
    private $username;
    private $password;
    private $client;

    private $accountsWithSubAccounts = array();
    private $row;

    private $addressFormatter;

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

        $this->addressFormatter = new AddressFormatter();
    }

    /**
     * @param $pathToImportFile
     * @param $debitAdjustmentID
     * @param $creditAdjustmentID
     * @return array
     */
    public function import($pathToImportFile, $debitAdjustmentID, $creditAdjustmentID)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);
            $this->validateServices($debitAdjustmentID, $creditAdjustmentID);

            if (!file_exists(__DIR__ . "/../log_output"))
            {
                mkdir(__DIR__ . "/../log_output");
            }

            $failureLogName = tempnam(__DIR__ . "/../log_output","account_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","account_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $this->row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $this->row++;
                try {
                    $this->createAccount($data, $debitAdjustmentID, $creditAdjustmentID, true);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    fwrite($failureLog,"Row {$this->row} failed: $returnMessage | " . implode(",",$data) . "\n");
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    fwrite($failureLog,"Row {$this->row} failed: {$e->getMessage()} | " . implode(",",$data) . "\n");
                    $returnData['failures'] += 1;
                    continue;
                }

                $returnData['successes'] += 1;
                fwrite($successLog,"Row {$this->row} succeeded for account ID " . trim($data[0]) . "\n");
            }

            /**
             * We do accounts with sub accounts last, as the children may not have been imported.
             */
            if (count($this->accountsWithSubAccounts) > 0)
            {
                foreach ($this->accountsWithSubAccounts as $row => $data)
                {
                    try {
                        $this->createAccount($data, $debitAdjustmentID, $creditAdjustmentID, false);
                    }
                    catch (ClientException $e)
                    {
                        $response = $e->getResponse();
                        $body = json_decode($response->getBody());
                        $returnMessage = implode(", ",(array)$body->error->message);
                        fwrite($failureLog,"Row $row failed: $returnMessage\n");
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
                    fwrite($successLog,"Row $row succeeded for account ID " . trim($data[0]) . "\n");
                }
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
        $requiredColumns = [ 0,1,2,3,7,9,10,13,16 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the account import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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
            'id' => (int)$data[0],
            'name' => trim($data[1]),
            'account_type_id' => (int)$data[2],
            'account_status_id' => (int)$data[3],
            'contact_name' => trim($data[16]),
        ];

        $unformattedAddress = [
            'line1' => trim($data[7]),
            'line2' => trim($data[8]),
            'city' => trim($data[9]),
            'state' => trim($data[10]),
            'county' => trim($data[11]),
            'zip' => trim($data[12]),
            'country' => trim($data[13]),
            'latitude' => trim($data[14]),
            'longitude' => trim($data[15]),
        ];

        $formattedAddress = $this->addressFormatter->formatAddress($unformattedAddress, false);

        $payload = array_merge($payload,$formattedAddress);

        /**
         * We don't do a ton of validation here, as the API call will fail if this data is invalid anyway.
         */
        if (trim($data[4]))
        {
            $payload['account_groups'] = explode(",",trim($data[4]));
        }
        if (trim($data[5]))
        {
            $payload['sub_accounts'] = explode(",",trim($data[5]));
        }
        if (trim($data[6]))
        {
            $carbon = new Carbon($data[6]);
            $now = Carbon::now();
            if ($carbon->gt($now))
            {
                $payload['next_bill_date'] = trim($carbon->toDateString());
            }
        }
        if (trim($data[17]))
        {
            $payload['role'] = trim($data[17]);
        }
        if (trim($data[18]))
        {
            $payload['email_address'] = trim($data[18]);
        }
        if (trim($data[19]))
        {
            $payload['email_message_categories'] = explode(",",trim($data[19]));
        }
        else
        {
            $payload['email_message_categories'] = [];
        }

        $phoneNumbers = [];
        if (trim($data[20]))
        {
            $phoneNumbers['work'] = [
                'number' => trim(preg_replace("/[^0-9]/","",$data[20])),
                'extension' => trim($data[21]),
            ];
        }
        if (trim($data[22]))
        {
            $phoneNumbers['home'] = [
                'number' => trim(preg_replace("/[^0-9]/","",$data[22])),
                'extension' => null,
            ];
        }
        if (trim($data[23]))
        {
            $phoneNumbers['mobile'] = [
                'number' => trim(preg_replace("/[^0-9]/","",$data[23])),
                'extension' => null,
            ];
        }
        if (trim($data[24]))
        {
            $phoneNumbers['fax'] = [
                'number' => trim(preg_replace("/[^0-9]/","",$data[24])),
                'extension' => null,
            ];
        }

        if (count($phoneNumbers) > 0)
        {
            $payload['phone_numbers'] = $phoneNumbers;
        }
        
        return $payload;
    }

    /**
     * Add a prior balance onto the account
     * @param $data
     * @return bool
     */
    private function addPriorBalanceIfRequired($data, $debitAdjustmentID, $creditAdjustmentID)
    {
        $id = (int)trim($data[0]);

        if (trim($data[25]))
        {
            $priorBalance = number_format(trim((float)$data[25]),2,".","");
            if ($priorBalance != 0)
            {
                if ($priorBalance > 0)
                {
                    $serviceID = $debitAdjustmentID;
                }
                else
                {
                    $serviceID = $creditAdjustmentID;
                }

                $response = $this->client->post($this->uri . "/api/v1/accounts/$id/services", [
                    'headers' => [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                    ],
                    'auth' => [
                        $this->username,
                        $this->password,
                    ],
                    'json' => [
                        'service_id' => $serviceID,
                        'prorate' => false,
                        'amount' => abs($priorBalance)
                    ]
                ]);
            }
        }

        return true;
    }

    /**
     * Validate that the service IDs are valid debit/credit adjustment services.
     * @param $debitAdjustmentID
     * @param $creditAdjustmentID
     */
    private function validateServices($debitAdjustmentID, $creditAdjustmentID)
    {
        try {
            $response = $this->client->get($this->uri . "/api/v1/system/services/$debitAdjustmentID", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);
        }
        catch (ClientException $e)
        {
            throw new InvalidArgumentException("$debitAdjustmentID is not a valid service ID.");
        }

        $objResponse = json_decode($response->getBody());
        if ($objResponse->data->type != "adjustment" || $objResponse->data->application != "debit")
        {
            throw new InvalidArgumentException("$debitAdjustmentID is not a valid debit adjustment service.");
        }

        try {
            $response = $this->client->get($this->uri . "/api/v1/system/services/$creditAdjustmentID", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);
        }
        catch (ClientException $e)
        {
            throw new InvalidArgumentException("$creditAdjustmentID is not a valid service ID.");
        }

        $objResponse = json_decode($response->getBody());
        if ($objResponse->data->type != "adjustment" || $objResponse->data->application != "credit")
        {
            throw new InvalidArgumentException("$creditAdjustmentID is not a valid credit adjustment service.");
        }

        return;
    }

    /**
     * Create a new account using the Sonar API
     * @param $data
     * @param $creditAdjustmentID
     * @param $debitAdjustmentID
     * @param bool $delaySubAccounts
     * @return bool
     */
    private function createAccount($data, $debitAdjustmentID, $creditAdjustmentID, $delaySubAccounts = true)
    {
        $payload = $this->buildPayload($data);
        if (array_key_exists("sub_accounts",$payload) && $delaySubAccounts === true)
        {
            $this->accountsWithSubAccounts[$this->row] = $data;
            return false;
        }

        $response = $this->client->post($this->uri . "/api/v1/accounts", [
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

        return $this->addPriorBalanceIfRequired($data, $debitAdjustmentID, $creditAdjustmentID);
    }
}
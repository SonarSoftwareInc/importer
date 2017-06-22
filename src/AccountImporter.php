<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Carbon\Carbon;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AccountImporter extends AccessesSonar
{
    private $addressFormatter;

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->addressFormatter = new AddressFormatter();
    }

    /**
     * @param $pathToImportFile
     * @return array
     * @throws Exception
     */
    public function import($pathToImportFile)
    {
        $masterAccountsToImport = [];
        $subAccountsToImport = [];

        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","account_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                try {
                    $payload = $this->buildPayload($data);
                }
                catch (Exception $e)
                {
                    throw new Exception("When building a payload for the following account, the payload generation failed with {$e->getMessage()}: " . implode(",",$data));
                }

                if (array_key_exists("sub_accounts", $payload)) {
                    array_push($subAccountsToImport, $data);
                    continue;
                }

                array_push($masterAccountsToImport, $data);
            }

            $allAccounts = array_merge($masterAccountsToImport, $subAccountsToImport);

            $requests = function () use ($allAccounts)
            {
                foreach ($allAccounts as $account)
                {
                    yield new Request("POST",$this->uri . "/api/v1/accounts", [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                        'Authorization' => 'Basic '. base64_encode($this->username.':'.$this->password),
                    ]
                    ,json_encode($this->buildPayload($account)));
                }
            };


            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $allAccounts)
                {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode > 201)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $line = $allAccounts[$index];
                        array_push($line,$body);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                    else
                    {
                        $returnData['successes'] += 1;
                        fwrite($successLog,"Import succeeded for account ID {$allAccounts[$index][0]}" . "\n");
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $allAccounts)
                {
                    $response = $reason->getResponse();
                    if ($response !== null)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $returnMessage = implode(", ",(array)$body->error->message);
                    }
                    else
                    {
                        $returnMessage = "Null response back from Sonar instance!",
                    }
                    $line = $allAccounts[$index];
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
        $requiredColumns = [ 0,1,2,3,7,9,10,13];

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

            if ($data[4])
            {
                $boom = explode(",",$data[4]);
                foreach ($boom as $kaboom)
                {
                    if (!is_numeric($kaboom))
                    {
                        throw new InvalidArgumentException("There is data entered in column 5 for row $row, but it does not match the required format. This column is for groups, and must contain either a single integer, or a set of integers separated by commas. $kaboom is not a valid entry.");
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
            'contact_name' => trim($data[16]) ? trim($data[16]) : trim($data[1]),
        ];

        $unformattedAddress = [
            'line1' => trim($data[7]),
            'line2' => trim($data[8]),
            'city' => trim($data[9]) ? trim($data[9]) : getenv('DEFAULT_CITY'),
            'state' => strlen(trim($data[10])) === 2 ? strtoupper(trim($data[10])) : ucwords(trim($data[10])),
            'county' => trim($data[11]) ? trim($data[11]) : getenv('DEFAULT_COUNTY'),
            'zip' => trim($data[12]),
            'country' => trim($data[13]),
            'latitude' => trim($data[14]),
            'longitude' => trim($data[15]),
        ];

        $formattedAddress = $this->addressFormatter->formatAddress($unformattedAddress, false, true);

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
}
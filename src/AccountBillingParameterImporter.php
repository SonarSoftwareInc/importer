<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;

class AccountBillingParameterImporter
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
        $dotenv->overload();
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

            $failureLogName = tempnam(__DIR__ . "/../log_output","account_billing_parameter_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","account_billing_parameter_import_successes");
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
                    $this->updateAccountBillingParameters($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    fputcsv($failureLog,array_merge($data,$returnMessage));
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    fputcsv($failureLog,array_merge($data,$e->getMessage()));
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
        $requiredColumns = [ 0 ];

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
                    if ((int)trim($data[1]) > 28 || (int)trim($data[1]) < 1)
                    {
                        throw new InvalidArgumentException("{$data[1]} is an invalid bill day in row $row.");
                    }
                }

                if (trim($data[2]))
                {
                    if ((int)trim($data[2]) < 0)
                    {
                        throw new InvalidArgumentException("{$data[2]} is an invalid due days value in row $row.");
                    }
                }

                if (trim($data[3]))
                {
                    if ((int)trim($data[3]) < 0)
                    {
                        throw new InvalidArgumentException("{$data[3]} is an invalid grace days value in row $row.");
                    }
                }

                if (trim($data[4]))
                {
                    try {
                        $graceUntil = Carbon::createFromFormat("Y-m-d",trim($data[4]));
                    }
                    catch (Exception $e)
                    {
                        throw new InvalidArgumentException("{$data[4]} is an invalid grace until value in row $row, it must be a date formatted as Y-M-D (e.g. 2016-06-01)");
                    }

                    /** This will potentially fail if the timezone of this system is wildly different than Sonar, but the API will reject it if we get that far. */
                    $now = Carbon::now();
                    if ($graceUntil->lte($now))
                    {
                        throw new InvalidArgumentException("Grace until must be in the future. It is not on row $row.");
                    }
                }

                if (trim($data[5]))
                {
                    if ((int)trim($data[5]) < 0)
                    {
                        throw new InvalidArgumentException("{$data[5]} is an invalid months to bill value in row $row.");
                    }
                }

                if (trim($data[8]))
                {
                    if ((int)trim($data[8]) > 28 || (int)trim($data[8]) < 1)
                    {
                        throw new InvalidArgumentException("{$data[8]} is an invalid separate invoice day in row $row.");
                    }
                }

                if (trim($data[9]))
                {
                    if ((int)trim($data[9]) < 0)
                    {
                        throw new InvalidArgumentException("{$data[9]} is an invalid auto pay day in row $row.");
                    }
                }

                if (trim($data[10]))
                {
                    if (!in_array(trim(strtolower($data[10])),['invoice','statement']))
                    {
                        throw new InvalidArgumentException("{$data[10]} is an invalid bill mode in row $row, must be one of 'invoice' or 'statement'");
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
        $payload = [];
        if (trim($data[1]))
        {
            $payload['bill_day'] = (int)trim($data[1]);
        }
        if (trim($data[2]))
        {
            $payload['due_days'] = (int)trim($data[2]);
        }
        if (trim($data[3]))
        {
            $payload['grace_days'] = (int)trim($data[3]);
        }
        if (trim($data[4]))
        {
            $payload['grace_until'] = trim($data[4]);
        }
        if (trim($data[5]))
        {
            $payload['months_to_bill'] = (int)trim($data[5]);
        }
        if (trim($data[6]))
        {
            $payload['tax_exempt'] = (boolean)trim($data[6]);
        }
        if (trim($data[7]))
        {
            $payload['print_invoice'] = (boolean)trim($data[7]);
        }
        if (trim($data[8]))
        {
            $payload['separate_invoice_day_enabled'] = true;
            $payload['invoice_day'] = (int)trim($data[8]);
        }
        if (trim($data[9]))
        {
            $payload['auto_pay_days'] = (int)trim($data[9]);
        }
        if (trim($data[10]))
        {
            $payload['bill_mode'] = strtolower(trim($data[10]));
        }
        return $payload;
    }

    /**
     * @param $data
     * @return mixed
     */
    private function updateAccountBillingParameters($data)
    {
        $payload = $this->buildPayload($data);
        if (count($payload) === 0)
        {
            return null;
        }

        $accountID = (int)trim($data[0]);

        return $this->client->patch($this->uri . "/api/v1/accounts/$accountID/billing_parameters", [
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
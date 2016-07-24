<?php

namespace SonarSoftware\Importer;

use SonarSoftware\Importer\Extenders\AccessesSonar;
use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class BalanceImporter extends AccessesSonar
{
    private $debitAdjustmentID;
    private $creditAdjustmentID;

    public function __construct($debitAdjustmentID, $creditAdjustmentID)
    {
        parent::__construct();
        $this->debitAdjustmentID = $debitAdjustmentID;
        $this->creditAdjustmentID = $creditAdjustmentID;
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
            $this->validateServices();

            $failureLogName = tempnam(getcwd() . "/log_output","balance_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","balance_import_successes");
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
                    $this->updateBalance($data[0], $data[1]);
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
     * Add a prior balance onto the account
     * @param $accountID
     * @param $balance
     * @return bool
     * @internal param $data
     */
    public function updateBalance($accountID, $balance)
    {
        $priorBalance = number_format(trim((float)$balance),2,".","");
        if ($priorBalance != 0)
        {
            if ($priorBalance > 0)
            {
                $serviceID = $this->debitAdjustmentID;
            }
            else
            {
                $serviceID = $this->creditAdjustmentID;
            }

            $response = $this->client->post($this->uri . "/api/v1/accounts/$accountID/services", [
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

        return true;
    }

    /**
     * Validate that the service IDs are valid debit/credit adjustment services.
     */
    private function validateServices()
    {
        try {
            $response = $this->client->get($this->uri . "/api/v1/system/services/" . (int)$this->debitAdjustmentID, [
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
            throw new InvalidArgumentException("$this->debitAdjustmentID is not a valid service ID.");
        }

        $objResponse = json_decode($response->getBody());
        if ($objResponse->data->type != "adjustment" || $objResponse->data->application != "debit")
        {
            throw new InvalidArgumentException("$this->debitAdjustmentID is not a valid debit adjustment service.");
        }

        try {
            $response = $this->client->get($this->uri . "/api/v1/system/services/" . (int)$this->creditAdjustmentID, [
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
            throw new InvalidArgumentException("$this->creditAdjustmentID is not a valid service ID.");
        }

        $objResponse = json_decode($response->getBody());
        if ($objResponse->data->type != "adjustment" || $objResponse->data->application != "credit")
        {
            throw new InvalidArgumentException("$this->creditAdjustmentID is not a valid credit adjustment service.");
        }

        return;
    }
}
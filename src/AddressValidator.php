<?php

namespace SonarSoftware\Importer;

use InvalidArgumentException;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AddressValidator extends AccessesSonar
{
    private $row;

    private $addressFormatter;

    public function __construct()
    {
        parent::__construct();
        $this->addressFormatter = new AddressFormatter();
    }

    /**
     * Validate the address in an account import
     * @param $pathToImportFile
     * @return array
     */
    public function validate($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile, "r")) !== FALSE) {
            $tempFile = tempnam(getcwd(),"validatedAddresses");
            $tempHandle = fopen($tempFile, "w");

            $this->validateImportFile($pathToImportFile);


            $failureLogName = tempnam(getcwd() . "/log_output", "address_validator_failures");
            $failureLog = fopen($failureLogName, "w");

            $successLogName = tempnam(getcwd() . "/log_output", "address_validator_successes");
            $successLog = fopen($successLogName, "w");

            $returnData = [
                'validated_file' => $tempFile,
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $this->row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $this->row++;
                try {
                    $validatedRow = $this->validateAddress($data);
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
                    fputcsv($failureLog,array_merge($data,[$e->getMessage()]));
                    $returnData['failures'] += 1;
                    continue;
                }

                fputcsv($tempHandle, $validatedRow);
                $returnData['successes'] += 1;
                fwrite($successLog,"Row {$this->row} succeeded for account ID " . trim($data[0]) . "\n");
            }

        } else {
            throw new InvalidArgumentException("File could not be opened.");
        }

        fclose($tempHandle);
        fclose($failureLog);
        fclose($successLog);

        return $returnData;
    }

    /**
     * Either cleans up the address, or throws an exception with a failure message
     * @param $data
     * @return mixed
     */
    private function validateAddress($data)
    {
        $unformattedAddress = [
            'line1' => trim($data[7]),
            'city' => trim($data[9]),
            'state' => trim($data[10]),
            'county' => trim($data[11]),
            'zip' => trim($data[12]),
            'country' => trim($data[13]),
        ];

        $validatedAddress = $this->addressFormatter->formatAddress($unformattedAddress);
        $data[7] = $validatedAddress['line1'];
        $data[8] = array_key_exists("line2",$validatedAddress) ? $validatedAddress['line2'] : null;
        $data[9] = $validatedAddress['city'];
        $data[10] = $validatedAddress['state'];
        $data[11] = array_key_exists("county",$validatedAddress) ? $validatedAddress['county'] : null;
        $data[12] = $validatedAddress['zip'];
        $data[13] = $validatedAddress['country'];
        $data[14] = array_key_exists("latitude",$validatedAddress) ? $validatedAddress['latitude'] : null;
        $data[15] = array_key_exists("longitude",$validatedAddress) ? $validatedAddress['longitude'] : null;

        return $data;
    }

    /**
     * Validate all the data in the import file.
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 7,9,10,13 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the address validation call, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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
}
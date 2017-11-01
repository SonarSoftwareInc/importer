<?php

namespace SonarSoftware\Importer;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use parseCSV;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class TicketImporter extends AccessesSonar
{
    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        ini_set('auto_detect_line_endings',true);

        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output", "ticket_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","ticket_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $validData = [];
            $additionalComments = [];
            $ticketIDs = [];

            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                array_push($validData, $data);
                $additionalCommentsForThisTicket = [];
                $i = 9;
                while (isset($data[$i]))
                {
                    if (trim($data[$i]) == null)
                    {
                        break;
                    }
                    array_push($additionalCommentsForThisTicket,$data[$i]);
                    $i++;
                }
                array_push($additionalComments,$additionalCommentsForThisTicket);
            }

            $requests = function () use ($validData)
            {
                foreach ($validData as $validDatum)
                {
                    yield new Request("POST", $this->uri . "/api/v1/tickets", [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                        ]
                        , json_encode($this->buildPayload($validDatum)));
                }
            };

            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, &$ticketIDs)
                {
                    $statusCode = $response->getStatusCode();
                    $body = json_decode($response->getBody()->getContents());

                    if ($statusCode > 201)
                    {
                        $line = $validData[$index];
                        array_push($line,$body);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                    else
                    {
                        $returnData['successes'] += 1;
                        fwrite($successLog,"Import succeeded for account ID {$validData[$index][0]}" . "\n");
                        $ticketIDs[$index] = $body->data->id;
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData)
                {
                    $response = $reason->getResponse();
                    if ($response)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $returnMessage = implode(", ",(array)$body->error->message);
                    }
                    else
                    {
                        $returnMessage = "No response returned from Sonar.";
                    }
                    $line = $validData[$index];
                    array_push($line,$returnMessage);
                    fputcsv($failureLog,$line);
                    $returnData['failures'] += 1;
                }
            ]);

            $promise = $pool->promise();
            $promise->wait();

            //Now for ticket comments!
            $requests = function () use ($ticketIDs, $additionalComments)
            {
                foreach ($ticketIDs as $index => $ticketID)
                {
                    if (isset($additionalComments[$index]))
                    {
                        foreach ($additionalComments[$index] as $additionalCommentBody)
                        {
                            yield new Request("POST", $this->uri . "/api/v1/tickets/$ticketID/ticket_comments", [
                                    'Content-Type' => 'application/json; charset=UTF8',
                                    'timeout' => 30,
                                    'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                                ]
                                , json_encode([
                                    'text' => $additionalCommentBody
                                ]));
                        }
                    }
                }
            };

            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, &$ticketIDs)
                {
                    //Just do nothing here, too late to deal with any failures.
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData)
                {
                    //Just do nothing here, too late to deal with any failures.
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
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [0,7];


        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 0, ",",'"')) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the ticket import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (!$data[1] && !$data[2])
                {
                    throw new InvalidArgumentException("You must have either a ticket group or user ID on row $row.");
                }

                if ($data[3])
                {
                    if (!is_numeric($data[3]))
                    {
                        throw new InvalidArgumentException("In the ticket import, the account number column is not numeric on row $row, and it must be.");
                    }
                }

                if ($data[4])
                {
                    try {
                        new Carbon($data[4]);
                    }
                    catch (Exception $e)
                    {
                        throw new InvalidArgumentException("{$data[4]} is not a valid date on row $row.");
                    }
                }

                if ($data[5])
                {
                    $priority = (int)$data[5];
                    if (!in_array($priority,[1,2,3,4]))
                    {
                        throw new InvalidArgumentException("{$data[4]} is not a valid priority on row $row.");
                    }
                }

                if ($data[6])
                {
                    $boom = explode(",",$data[6]);
                    foreach ($boom as $bewm)
                    {
                        if (!is_numeric($bewm))
                        {
                            throw new InvalidArgumentException("{$data[6]} is not a valid list of category IDs on row $row.");
                        }
                    }
                }

                unset($boom);
                unset($data);
           }
        }
        else
        {
            throw new InvalidArgumentException("Could not open import file.");
        }
    }

    /**
     * @param $line
     * @return array
     */
    private function buildPayload($line)
    {
        if ($line[4])
        {
            $carbon = new Carbon($line[4]);
        }

        $payload = [
            'subject' => $line[0],
            'type' => 'internal',
            'ticket_group_id' => $line[1],
            'category_ids' => isset($line[6]) ? explode(",",$line[6]) : null,
            'user_id' => $line[2],
            'due_date' => $line[4] ? $carbon->toDateString() : null,
            'priority' => trim($line[5]) ? trim($line[5]) : 4,
            'comment' => $line[7],
            'open' => $line[8] == 1 ? true : false,
        ];

        if ($line[3])
        {
            $payload['assignee'] = "accounts";
            $payload['assignee_id'] = $line[3];
        }

        return $payload;
    }
}
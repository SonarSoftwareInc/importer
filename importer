<?php
use Dotenv\Dotenv;
use League\CLImate\CLImate;
use SonarSoftware\Importer\AddressValidator;
use SonarSoftware\Importer\Importer;

require_once("vendor/autoload.php");

loadDotenv();

$climate = new CLImate();
$input = $climate->red()->bold()->input("This will import data into " . getenv('URI') . ", please confirm to proceed.");
$input->accept(['y','n'],true);
if ($input->prompt() != 'y') {
    $climate->lightGreen()->out("Goodbye.");
    return;
}

prompt($climate);

/**
 * Load the dotenv file
 */
function loadDotenv()
{
    $dotenv = new Dotenv(__DIR__);
    $dotenv->overload();
    $dotenv->required(
        [
            'URI',
            'USERNAME',
            'PASSWORD',
        ]
    );
}

/**
 * Prompt at the main menu for input
 * @param CLImate $climate
 */
function prompt(CLImate $climate)
{
    $options = [
        'validate' => 'Validate Addresses',
        'accounts' => 'Import Accounts',
        'accountBillingParameters' => 'Import Account Billing Parameters',
        'accountCustomFields' => 'Import Account Custom Fields',
        'accountFiles' => 'Import Account Files',
        'accountIps' => 'Import Account IP Addresses',
        'accountIpsWithMacAddresses' => 'Import Account IP Address/MAC combinations',
        'accountNotes' => 'Import Account Notes',
        'accountPackages' => 'Import Account Packages',
        'accountSecondaryAddresses' => 'Import Account Secondary Addresses',
        'accountServices' => 'Import Account Services',
        'callLogs' => 'Import Call Logs',
        'contacts' => 'Import Contacts',
        'inventoryItems' => 'Import Inventory Items',
        'ipPools' => 'Import IP Pools',
        'networkSites' => 'Import Network Sites',
        'networkSiteIps' => 'Import Network Site IP Addresses',
        'networkSiteIpsWithMacAddresses' => 'Import Network Site IP Address/MAC combinations',
        'radiusAccounts' => 'Import RADIUS Accounts',
        'scheduledJobs' => 'Import Scheduled Jobs',
        'services' => 'Import Services',
        'subnets' => 'Import Subnets',
        'tickets' => 'Import Tickets',
        'tokenizedBankAccounts' => 'Import Tokenized Bank Accounts',
        'untokenizedBankAccounts' => 'Import Untokenized Bank Accounts',
        'tokenizedCreditCards' => 'Import Tokenized Credit Cards',
        'untokenizedCreditCards' => 'Import Untokenized Credit Cards',
        'updateBalances' => 'Update Balances',
        'flush' => 'Flush Address Cache',
        'quit' => 'Quit',
    ];

    $input = $climate->lightGreen()->radio('Please select an action to perform.',$options);
    $response = $input->prompt();

    if (isset($options[$response]))
    {
        $climate->lightGray()->out("OK - running the '{$options[$response]}' tool.");
    }

    switch ($response)
    {
        case "validate":
            validateAddresses($climate);
            break;
        case "accounts":
        case "accountCustomFields":
        case "contacts":
        case "accountServices":
        case "accountPackages":
        case "accountBillingParameters":
        case "accountSecondaryAddresses":
        case "tokenizedCreditCards":
        case "untokenizedCreditCards":
        case "tokenizedBankAccounts":
        case "untokenizedBankAccounts":
        case "accountFiles":
        case "accountNotes":
        case "networkSites":
        case "inventoryItems":
        case "accountIpsWithMacAddresses":
        case "accountIps":
        case "networkSiteIpsWithMacAddresses":
        case "services":
        case "ipPools":
        case "scheduledJobs":
        case "subnets":
        case "radiusAccounts":
        case "callLogs":
        case "tickets":
        case "networkSiteIps":
            importGeneric($climate,$response);
            break;
        case "updateBalances":
            updateBalances($climate);
            break;
        case "flush":
            flushAddressCache($climate);
            break;
        case "quit":
            $climate->lightGreen()->out('Goodbye.');
            return;
            break;
        default:
            $climate->red()->out("Sorry, $response is not a valid selection. Please try again.");
            prompt($climate);
            break;
    }
}

/**
 * @param CLImate $climate
 */
function updateBalances(CLImate $climate)
{
    $input = $climate->lightGreen()->input("Please enter the complete path to the account balances file:");
    $filename = $input->prompt();
    if (!file_exists($filename))
    {
        $climate->red()->out($filename . " is not a valid file!");
        updateBalances($climate);
    }

    $input = $input = $climate->lightGreen()->input("Please enter the ID of a DEBIT adjustment service to use:");
    $debitAdjustmentID = $input->prompt();
    if (!is_numeric($debitAdjustmentID))
    {
        $climate->red()->out("The debit adjustment ID must be numeric!");
        updateBalances($climate);
    }

    $input = $input = $climate->lightGreen()->input("Please enter the ID of a CREDIT adjustment service to use:");
    $creditAdjustmentID = $input->prompt();
    if (!is_numeric($creditAdjustmentID))
    {
        $climate->red()->out("The credit adjustment ID must be numeric!");
        updateBalances($climate);
    }

    $lines = getLines($filename);
    $climate->lightGreen()->out("Importing $lines balances. Please be patient, this may take some time.");

    $importer = new Importer();
    try {
        $output = $importer->updateBalances($filename, $debitAdjustmentID, $creditAdjustmentID);
    }
    catch (Exception $e)
    {
        $climate->red()->out($e->getMessage());
        updateBalances($climate);
        return;
    }

    $climate->lightGreen()->out("Balance update complete!");
    $climate->white()->out("There were {$output['successes']} balances successfully updated.");
    if ($output['failures'] > 0)
    {
        $climate->red()->out("There were {$output['failures']} failures, logged at {$output['failure_log_name']}.");
    }

    prompt($climate);
}

/**
 * @param CLImate $climate
 * @param $entity
 */
function importGeneric(CLImate $climate, $entity)
{
    $friendlyName = strtolower(implode(" ",preg_split('/(?=[A-Z])/',$entity)));
    $input = $climate->lightGreen()->input("Please enter the complete path to the $friendlyName file:");
    $filename = $input->prompt();
    if (!file_exists($filename))
    {
        $climate->red()->out($filename . " is not a valid file!");
        importGeneric($climate, $entity);
    }

    $lines = getLines($filename);
    $climate->lightGreen()->out("Importing $lines $friendlyName. Please be patient, this may take some time.");

    $method = "import" . ucwords($entity);

    $importer = new Importer();
    try {
        $output = $importer->$method($filename);
    }
    catch (Exception $e)
    {
        $climate->red()->out($e->getMessage());
        importGeneric($climate, $entity);
        return;
    }

    $climate->lightGreen()->out(ucwords($entity) . " import complete!");
    $climate->white()->out("There were {$output['successes']} $friendlyName successfully imported.");
    if ($output['failures'] > 0)
    {
        $climate->red()->out("There were {$output['failures']} failures, logged at {$output['failure_log_name']}.");
    }

    prompt($climate);
}

/**
 * Flush the cached addresses
 * @param CLImate $climate
 */
function flushAddressCache(CLImate $climate)
{
    $climate->lightGreen()->out("Flushing the address cache.");
    $client = new \Predis\Client();
    $client->flushall();
    prompt($climate);
}

/**
 * Validate addresses
 * @param $climate
 */
function validateAddresses(CLImate $climate)
{
    $input = $climate->lightGreen()->input("Please enter the complete path to the accounts CSV file:");
    $filename = $input->prompt();
    if (!file_exists($filename))
    {
        $climate->red()->out($filename . " is not a valid file!");
        validateAddresses($climate);
    }

    $lines = getLines($filename);
    $climate->lightGreen()->out("Validating $lines entries. Please be patient, this may take some time.");

    $validator = new AddressValidator();
    try {
        $output = $validator->validate($filename);
    }
    catch (Exception $e)
    {
        $climate->red()->out($e->getMessage());
        prompt($climate);
        return;
    }

    $climate->lightGreen()->out("Validation complete!");
    $climate->white()->out("There were {$output['successes']} lines successfully validated, available at {$output['validated_file']}.");
    if ($output['failures'] > 0)
    {
        $climate->red()->out("There were {$output['failures']} failures, logged at {$output['failure_log_name']}.");
    }

    $climate->lightCyan()->out("Cached addresses were used {$output['cache_hits']} times, and {$output['cache_fails']} addresses had to be geocoded.");

    prompt($climate);
}

/**
 * Count the lines in a file
 * @param $file
 * @return int
 */
function getLines($file)
{
    $f = fopen($file, 'rb');
    $lines = 0;

    while (!feof($f)) {
        $lines += substr_count(fread($f, 8192), "\n");
    }

    fclose($f);

    return $lines;
}
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
            break;
        case "quit":
            $climate->lightGreen()->out('Goodbye.');
            return;
            break;
        case "flush":
            //TODO: Write me
            break;
        default:
            $climate->red()->out("Sorry, $response is not a valid selection. Please try again.");
            prompt($climate);
            break;
    }
}

/**
 * Validate addresses
 * @param $climate
 */
function validateAddresses($climate)
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
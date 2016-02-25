# Sonar Importer
This PHP library uses the Sonar API to import data from a standard format into Sonar. **Please note that this tool is currently pre-release and not production ready.**

If you do not have a background in software development, you are not expected to use this tool yourself. Feel free to contact us at support@sonar.software for assistance.

##Installing
The recommended installation method is using [Composer](https://getcomposer.org "Composer"). You can install by running `composer require sonarsoftware/importer`. Alternatively, you can download the code directly from Github and include the necessary classes into your scripts.
However, we strongly recommend using Composer to simplify auto-loading.

##Setup
To setup the importer for use, create a .env file in the src directory by copying the *.env.example* file. Modify the **URI**, **USERNAME** and **PASSWORD** values to match your Sonar instance. The username and password must be for a user account
that has the appropriate permissions for the API. The safest option is to use a 'Super Admin' user.

##Templates
The **templates** folder has spreadsheets in it that describe the format of the CSVs that should be used to import data using this tool. Each spreadsheet has a tab with some basic instructions, and a tab for the CSV format. Most columns in the
formatting tab have notes with more in-depth descriptions.

##How to use
To use the importer, instantiate the Importer class.

`$importer = new SonarSoftware\Importer\Importer();`

##Importer output
Assuming there are no fatal errors (which will throw an exception) the importer will write logs into the **log_output** folder. This folder will contain a fail and success log file, which will report any failures, as well as successes. An import is
not fully successful unless the failure log file is completely empty and the "failures" count is 0!

The importer will return an array in the following format:

`[
     'successes' => 0,
     'failures' => 0,
     'failure_log_name' => $failureLogName,
     'success_log_name' => $successLogName
]`

You will receive an individual output for each function that is called. For example, if you import accounts, contacts and credit cards, you will have three sets of log files, one for each import.

###Importing accounts
To import accounts, call the function **importAccounts** on the Importer class, passing in the path to a properly formatted CSV file with account data. You will need to manipulate your data into the appropriate format before importing, by using the account template in the templates folder.

You should also input a debit adjustment service ID to use for positive prior balances and a credit adjustment service ID to use for negative prior balances, as the second and third parameters, respectively. In the example below, `1` is the ID of the debit adjustment service and `2` is the ID of the credit adjustment service. Ensure that the adjustment services allow access via the role of the user specified in your .env file!

`$results = $importer->importAccounts("/home/simon/accounts.csv", 1, 2);`
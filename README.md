# Sonar v1 Importer
This PHP library uses the Sonar API to import data from a standard format into Sonar.

### This tool is fairly technical. If you don't feel comfortable using this tool yourself, please contact onboarding@sonar.software for assistance.

## Installing

### Notes
This tool has been built and tested on Linux, specifically Ubuntu, although it is likely to function on any Linux distribution. It has not been tested on any other operating system.

The importer itself is written in PHP. Although this importer should work with PHP 5.5+, I recommend using PHP7 for the best performance. If you're using Ubuntu 16.x, you will have PHP7 installed by default.

### Setup
You can install this tool three ways - clone the repository, use [Composer](https://getcomposer.org) and download it via [Packagist](https://packagist.org/packages/sonarsoftware/importer), or click [here](https://github.com/SonarSoftware/importer/archive/master.zip), unzip it, and enter the folder. I recommend using git or Composer to install, so that you can easily keep it up to date.

**The importer utilizes Redis for caching, and you will need the Redis server installed. You can install this on Ubuntu by typing `sudo apt-get install redis-server`.**

To setup the importer for use, create a .env file in the `importer` directory by copying the *.env.example* file. Modify the **URI**, **USERNAME** and **PASSWORD** values to match your Sonar instance. The username and password must be for a user account
that has the appropriate permissions for the API. The safest option is to use a 'Super Admin' user. When running the importer, it will look for an .env file in the current directory, so make sure you're in the same directory as the .env file when running it. This allows for
the tool to be used by multiple users if necessary.

## Templates
The **templates** folder has spreadsheets in it that describe the format of the CSVs that should be used to import data using this tool. Each spreadsheet has a tab with some basic instructions, and a tab for the CSV format. Most columns in the
formatting tab have notes with more in-depth descriptions. When exporting the CSVs, they should be comma delimited, and strings should be wrapped in double quotes.

All the importers described below use a template from the **templates** folder.

## Using the importer

### First Steps

1. Before importing, we also **strongly recommend** you disable 'Daily Billing' under Financial > Billing > Configuration. You don't want accounts getting billed until your import is complete and verified!
2. Create all needed services, taxes, address types, groups, statuses, etc. The intent of the importer is to import mass data - accounts, contacts, credit cards, etc. Parts of the importer will require you to reference the status and type of an account, or the type of an address. These will need to be created before you begin. I'd strongly recommend creating a small script to build these items via the API, so that you can easily reset your system after a failed import if needed. There are some importers setup to import basic service structures and some of the other items mentioned here, but it is often easier to just do them by hand.
3. Setup your payment processor information in Sonar, if you are importing payment methods. **You will need a functioning payment processor to import credit cards or eCheck accounts.**
4. Setup your billing defaults under **Financial > Billing > Defaults**. Having these set to correct values prior to import will help avoid issues with bill dates being set too far in the future.
5. Double check your data - failures in the import CSVs (data in an incorrect column) can have very unintended consequences. For example, putting the account status ID in the prior balance column will definitely not perform the way you want it to..
6. Use the address validator to validate all CSVs with addresses in them prior to import. This step is **very important**, as you will almost certainly have many failures without properly validated addresses.

### CSV Formatting
Your CSV files should be comma separated. Each column must be included, even if it is optional. An optional column can just have no data entered. Strings should be wrapped in double quotes and double quotes inside strings should be escaped with a backslash.

### How to use
To use the importer, run the `importer` command line tool by typing `php importer` from the directory you unzipped the importer into. Select an option from the menu and you will be prompted for further information. **Make sure you run your accounts CSV through the 'Validate Addresses' function before importing!**

### Importer output
Assuming there are no fatal errors (which will throw an exception) the importer will write logs into the **log_output** folder. This folder will contain a fail and success log file, which will report any failures, as well as successes. An import is
not fully successful unless the failure log file is completely empty and the "failures" count is 0! Each function will also provide a path to the failure log file if it contains any failures.

### Validating account addresses
Before importing your accounts, it is important that the addresses are validated. Sonar requires well formatted addresses, with two character country codes (and counties, for US addresses.) You can feed your account import document into the address validator prior to running the import. This will attempt to validate each address, return failures for any bad addresses, and return a new CSV file with cleaned up addresses for any that can be validated.

If any addresses are rejected, that means cannot be geocoded. You will have to fix these addresses by hand before importing them to Sonar. Go through each entry in the failure log, clean it up, and then add it to the validated list. You can then feed the validated list to the account importer.

The requirement for addresses in Sonar is a line1 value, a city, a state, a zip/postal code, a country, and a county (if the address is in the US, and the state has counties.) You can get a list of valid counties from the Sonar API at `/api/v1/_data/counties/{state}` where `{state}` is the two character state (e.g. WI, AZ.) If some addresses cannot validate,
there is no point in running them through the validator repeatedly - fix them by hand, and move on to the account import. Bear in mind that any failures in the address validator will translate into failures in the account importer, so make sure you run through this step first!

**Please note that address validation results are cached for a period of time, so that running the address validator repeatedly is much faster. However, if you make significant changes that require a cache clear, you can do so under the `Tools` menu.**

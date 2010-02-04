Dreamhost Full Auto Backup
==========================

You can read more from Dreamhost's Personal Backpus at [http://wiki.dreamhost.com/Personal_Backup](http://wiki.dreamhost.com/Personal_Backup "Dreamhost Personal Backup")

Installation
------------

It's very simple:

*   Activate your dreamhost backups user account;
*   Set up passwordless login between your main account and the backups server;
*   Create an API key with the following functions *user-list_users*, *mysql-list_dbs*, *mysql-list_users*;
*   Create a folder for the mysql dumps;
*   Download the script and put it anywhere you want.

Execution
---------

Note that you can give the script any name you want. Also, make sure you provide a path to a PHP5 version. 

You can check with:

`$ php -v`

And to find the location:

`$ which php`

If this returns what you need you don't have to provide the full path bellow, unless you're doing it from the panel's cronjobs.

At this time, on dreamhost, the path to PHP5 is

`/usr/local/php5/bin/php`

### Method 1 ###

`$ /path/to/php -q path/to/script api_key`

### Method 2 ###

Add this to the beginning of the file

`#!/path/to/php -q`

...then make it executable with

`$ chmod u+x path/to/script`

...and run it as

`$ path/to/script api_key`
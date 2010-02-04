Dreamhost Full Auto Backup
==========================

You can read more from Dreamhost's Personal Backpus at <http://wiki.dreamhost.com/Personal_Backup>

Introduction
------------

I needed a simple script to backup all my mysql databases and users on my dreamhost account. 

With this solution I don't need to store here all my sensitive login for mysqldump, nor do I need to 
set up ssh public keys for passwordless connections, whenever I add a new user (or remove!).

I just have a passwordless ssh login set up from my main account to the backup account, and run the 
script from there as a cronjob.

The API key must be provided as an argument to the script. This is also to prevent storing sensitive 
data on the file.

I use PHP's SimpleXMLElement class to easily get my data from the API. That's where all the data 
necessary (including passwords), comes from. I also use an Expect script to remotely ssh into the 
other user accounts and rsync them from there.

So I don't need to do anything when I create/delete databases/users. This is the purpose of a fully 
automated backup system. The more automated the better. Very little configuration is needed.


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

If this returns what you need you don't have to provide the full path bellow, unless you're doing it 
from the panel's cronjobs.

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


Contribute
----------

I'm interested in improvements, so be free to contribute.

Suggestions: 

*   Make this simpler; 
*   Create local mysql backup folder if it doesn't exist;
*   Improve Expect script to be smarter.

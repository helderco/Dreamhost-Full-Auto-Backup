<?php
/*** 
 * 2010/01/17 - Helder Correia - http://heldercorreia.com/
 *
 * Title: Dreamhost Full Account Backup
 *
 * Intro: I needed a simple script to backup all my mysql databases and users 
 * 		  on my dreamhost account. 
 *
 *        With this solution I don't need to store here all my sensitive login 
 * 	      for mysqldump, nor do I need to set up ssh public keys for passwordless 
 *        connections, whenever I add a new user (or remove!).
 *
 *        I just have a passwordless ssh login set up from my main account to 
 * 		  the backup account, and run this script from there as a cronjob.
 *
 * 		  The API key must be provided as an argument to the script. This is also
 *        to prevent storing sensitive data on this file.
 *
 *        I use PHP's SimpleXMLElement class to easily get my data from the API.
 *        That's where all the data necessary (including passwords), comes from.
 *        I also use an Expect script to remotely ssh into the other user accounts
 *        and rsync them from there.
 *
 *        So I don't need to do anything when I create/delete databases/users. This
 *        is the purpose of a fully automated backup system. The more automated the better.
 *		  Very little configuration is needed here.
 * 
 * 		  I'm interested in improvements, so send me your patches to helder@heldercorreia.com.
 *		  Suggestions: make this simpler; create local mysql backup folder if it doesn't exist.
 **/

/*********************
 *** CONFIGURATION ***
 *********************/

// Specify you chosen directories (remote = backups user account)
// Note: No trailing slashes
$backups_dir  = 'backups'; // Local backups folder to store the dbs (e.g.: 'tmp')
$mysql_subdir = 'mysql';   // Remote (and local) folder for mysql dbs
$files_subdir = 'users';   // Remote folder for storing the backed up user accounts

// Specify excludes and includes for your files
// Note1: '__common__' is a special user and will be used on all users
// Note2: Read the manual on rsync to understand how to build the patterns
// Syntax: $(in|ex)cludes['user'] = array(pattern1, pattern2, etc...)
$excludes = array(
	'__common__'=>array(
		'/Maildir**',
		'/dh**',
		'/jabber**',
		'/logs**',
		'/tmp**'
	),
	'myuser'=>array(
		'/sites/**/www/cache/**',
		'/sites/**/www/forum/cache/**'
	)
);

$includes = array();


/*****************************************
 *** END CONFIG - DON'T EDIT PAST HERE ***
 *****************************************/
 
if ($_SERVER['argc']!=2) {
	exit ("You need to provide the dreamhost API key!");
}

$home = $_SERVER['HOME'];
$account_user = $_SERVER['USER'];
$begin_script = time();

function label($text)
{
	return "\n[".date('r')."] ".$text."\n";
}

echo "#################################################\n";
echo "### Starting dreamhost account backup process ###\n";
echo "###      ".date('r')."      ###\n";
echo "#################################################\n";

try {
	// Check if we can read/write to mysql subdir chosen
	$mysql_arquives = new DirectoryIterator($home.'/'.$backups_dir.'/'.$mysql_subdir);
	if (!$mysql_arquives->isReadable() || !$mysql_arquives->isWritable()) {
		throw new RuntimeException('No read or write access.');
	}
} catch (RuntimeException $e) {
	exit("Please make sure you provide a valid folder for the arquives, with read and write access.\n");
}

// Return an array with stdClass objects from API commands
function request_api($cmd)
{
	$api_key = $_SERVER['argv'][1];
    $xml = new SimpleXMLElement(
    	'https://api.dreamhost.com/?key='.$api_key.'&cmd='.$cmd.'&format=xml'
    	, null, true
    );
    if ((string) $xml->result != 'success') {
    	exit("Request for '$cmd' unsuccessful.\n");
    }
    // Use json decode/encode to convert the SimpleXMLElement objects into stdClass objects
    return json_decode(json_encode($xml->xpath('/dreamhost/data')));
}


/*********** PREPARE DATA FOR EASE OF USE ************/

$list_users   	  = request_api('user-list_users');
$list_mysql_dbs   = request_api('mysql-list_dbs');
$list_mysql_users = request_api('mysql-list_users');
	
$backup_user = null;
$shell_users = array();
$mysql_users = array();

foreach ($list_users as $user) {
	switch ($user->type) {
		case 'backup' : $backup_user = $user; break;
		case 'shell'  : $shell_users[$user->username] = $user; break;
	}
}

if (is_null($backup_user)) {
	exit('Please make sure you have your dreamhost backup user account activated!');
}

foreach ($list_mysql_users as $mysql_user) {	
	$mysql_users[$mysql_user->db][$mysql_user->username] = $mysql_user;
}


/*********** BACKUP MYSQL DATABASES ************/

echo label('Backing up databases...');

function backup_mysql($db, $arquive, $suffix = '') 
{
	$sql_file = "$arquive/{$db->db}{$suffix}.sql.gz";
	$mysql_dump = "mysqldump -c -u$db->user -p$db->password -h$db->home $db->db | gzip > $sql_file";
    
    $exit_status = null;
    system($mysql_dump, $exit_status);

    if ($exit_status == 0) {
        echo "{$db->db} was backed up successfully to {$sql_file}.\n";
        return true;
    }
    
    echo "WARNING: An error occured while attempting to backup {$db->db} to {$sql_file}.\n";
    return false;
}

foreach ($list_mysql_dbs as $mysql_db) {
	if (isset($mysql_users[$mysql_db->db][$account_user])) {
		$mysql_db->user = $account_user;
		$mysql_db->password = $shell_users[$account_user]->password;
	}
	else {
		list($mysql_user) = array_values(array_slice($mysql_users[$mysql_db->db], 0, 1));
		$mysql_db->user = $mysql_user->username;
		$mysql_db->password = $shell_users[$mysql_user->username]->password;
	}
	
    backup_mysql($mysql_db, $home.'/'.$backups_dir.'/'.$mysql_subdir, '.'.date('\ww'));
}

echo label('Syncing mysql backup with backup server...');

$connect = $backup_user->username.'@'.$backup_user->home;
echo shell_exec("rsync -e ssh -avz $backups_dir/$mysql_subdir $connect:$mysql_subdir/");
echo "\n";


/*********** BACKUP USER ACCOUNTS ************/

// On shell users different then the one running this script (main user),
// we have to connect through ssh first and then run rsync from there to
// the backup user account. We do this using an 'expect' script.
// To simplify things, don't set ssh public keys on those ones, since this
// script is expecting to provide the passwords (or improve the script!).
function rsync($ssh_auth, $ssh_pass, $rsync, $backup_pass)
{
$exp = 'log_user 0
exp_internal 0  ;# turn this on for debugging
set timeout -1

spawn ssh -q -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '.$ssh_auth.'

expect "?assword: "
send "'.$ssh_pass.'\r"

expect "\\\$"
send "'.$rsync.'\r"

expect "?assword: " 
send "'.$backup_pass.'\r"

log_user 1
expect string rtrim "total size is * speedup is"
log_user 0

expect "\\\$"
send "exit\r"
expect "*logout"
expect *';

$var = shell_exec("expect -c '$exp'");
$var = split("\r\n", $var);
array_pop($var); // remove the prompt from the returned output
return join("\n", $var)."\n";
}

// Prepends --exclude or --include on the respective elements.
// This allows simplification on the configuration at the top.
// $flag must be either 'ex' or 'in'
function cludes($stack, $flag) 
{
	$new_stack = array();
	foreach ($stack as $user=>$cludes) {
		foreach ($cludes as $clude) {
			$new_stack[$user][] = '--'.$flag.'clude '.$clude;
		}
	}
	return $new_stack;
}

// Extracts all the excludes and includes for a certain user, as a string.
// It fetches the common values automatically.
// $flag must be either 'ex' or 'in'
function user_cludes($user, $flag)
{
	$cludes = $flag.'cludes';
	global $$cludes;
	$stack = array();

	if (isset(${$cludes}['__common__'])) {
		$stack = ${$cludes}['__common__'];
	}	
	if (isset(${$cludes}[$user])) {
		$stack = array_merge($stack, ${$cludes}[$user]);
	}

	return join(' ', $stack);
}

// Return the rsync command based on a user and the 
// associated excludes and includes.
function get_rsync_cmd($user, $backup)
{
	global $files_subdir; 
	
	$excludes = user_cludes($user->username, 'ex');
	$includes = user_cludes($user->username, 'in');
	
	$root_d = '/home/'.$user->username.'/';
	$r_conn = "{$backup->username}@{$backup->home}:{$files_subdir}/{$user->username}/";
	$r_optn = '-auvz --delete --delete-excluded --timeout=43200 --force';
	$rs_cmd = "rsync -e ssh $r_optn $root_d $includes $excludes $r_conn";

	return $rs_cmd;
}

// Exclude the newly synced databases from the users backup
$excludes[$account_user][] = '/'.$backups_dir.'/'.$mysql_subdir.'**';

// Doing this mess to allow for easy configuration at the top
$includes = isset($includes) ? cludes($includes, 'in') : array();
$excludes = isset($excludes) ? cludes($excludes, 'ex') : array();
global $includes, $excludes;

// Loop through the shell users and rsync them with the backup user
foreach ($shell_users as $user) {
	echo label('Backing up user `'.$user->username.'`');
	$cmd = get_rsync_cmd($user, $backup_user);
    // I have set up an authorized connection from this user account 
    // (the main account) to the backup user account, so we can skip 
    // the prior ssh connection to rsync and rsync directly.
	if ($user->username == $account_user) {
		echo shell_exec($cmd);
	}
	else {
		$ssh = $user->username.'@'.$user->home;
		echo rsync($ssh, $user->password, $cmd, $backup_user->password);
	}
	echo "\n";
}

echo label('Backup complete. Have a nice day!');

$end_script = time();
$time_elapsed = gmdate('H\hi\ms\s', ($end_script - $begin_script));

echo sprintf("\nTotal time elapsed: %s\n\n", $time_elapsed);

exit(0);
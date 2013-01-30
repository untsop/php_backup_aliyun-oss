<?php

define('ossAccessId', ''); // required
define('ossAccessKey', ''); // required
define('ossBucket', ''); // required
define('ossFolder', ''); // required

// Will this script run "weekly", "daily", or "hourly"?
define('schedule','daily'); // required

require_once('include/backup.inc.php');

// You may place any number of .php files in the backups folder. They will be executed here.
foreach (glob(dirname(__FILE__) . "/backups/*.php") as $filename)
{
    include $filename;
}

/*

backupDBs - hostname, username, password, prefix, [post backup query]

  hostname = hostname of your MySQL server
  username = username to access your MySQL server (make sure the user has SELECT privliges)
  password = your password
  prefix = backup filenames will contain this prefix, this prevents overwriting other backups when you have more than one server backing up at once.
  post backup query = Optional: Any SQL statement you want to execute after the backups are completed. For example: PURGE BINARY LOGS BEFORE NOW() - INTERVAL 14 DAY;

*/
backupDBs('localhost','root','','','');

?>
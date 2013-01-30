<?php
// don't kill the long running script
set_time_limit(0);

if (!defined('debug')) {
	define('debug', true);
}

if (!defined('mysqlDumpOptions')) {
	define('mysqlDumpOptions', '--quote-names --quick --add-drop-table --add-locks --allow-keywords --disable-keys --extended-insert --single-transaction --create-options --comments --net_buffer_length=16384');
}

// includes
require_once('oss/sdk.class.php');

//Setup OSS class
$oss = new ALIOSS();

//delete old backups
switch (schedule) {
	case "weekly":
		deleteWeeklyBackups();
		break;
	case "hourly":
		deleteHourlyBackups();
		break;
	default:
		deleteDailyBackups();
		break;	
}

//Setup variables
$mysql_backup_options = mysqlDumpOptions;

// Backup functions

// Backup files and compress for storage
function backupFiles($targets, $prefix = '') {
  global $oss;
  
  if (schedule == "hourly") deleteHourlyBackups($prefix);
  
  foreach ($targets as $target) {
    
    if (debug == true) {
      echo "Backing up: $target\n";
    }
    
    if (strpos($target,'/') === 0) {
      $target = strrev(rtrim(strrev($target),'/'));
    }
    
    if (debug == true) {
      echo "Relative from root: $target\n";
    }
    
    // compress local files
    $cleanTarget = urlencode($target);
    `tar -cjf "$prefix-$cleanTarget.tar.bz2" -C / "$target"`;
    
    $backup_to = ossPath($prefix, $target."-backup.tar.bz2");
    
    if (debug == true) {
      echo "Backing up to: ".$backup_to."\n";
    }

    // upload to OSS
    $oss->upload_file_by_file(ossBucket, $backup_to, "$prefix-$cleanTarget.tar.bz2");
    
    // remove temp file
    `rm -rf "$prefix-$cleanTarget.tar.bz2"`;
  }
}

// Backup all Mysql DBs using mysqldump
function backupDBs($hostname, $username, $password, $prefix, $post_backup_query = '') {
  global $DATE, $oss, $mysql_backup_options;
  
	if (schedule == "hourly") deleteHourlyBackups($prefix);
  
  // Connecting, selecting database
  $link = mysql_connect($hostname, $username, $password) or die('Could not connect: ' . mysql_error());
  
  $query = 'SHOW DATABASES';
  $result = mysql_query($query) or die('Query failed: ' . mysql_error());
  
  $databases = array();
  
  while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
      foreach ($row as $database) {
      $databases[] = $database;
      }
  }
  
  // Free resultset
  mysql_free_result($result);

  //Run backups on each DB found
  foreach ($databases as $database) {
    backupDB($hostname, $username, $password, $database, $prefix, $post_backup_query = '');
  }
  
  //Run post backup queries if needed
  if ($post_backup_query != '') {
    $result = mysql_query($post_backup_query) or die('Query failed: ' . mysql_error());
  }
  
  // Closing connection
  mysql_close($link);
  
}

function backupDB($hostname, $username, $password, $database, $prefix, $post_backup_query = '') {
	global $oss, $mysql_backup_options;
	
	`/usr/bin/mysqldump $mysql_backup_options --no-data --host=$hostname --user=$username --password='$password' $database | bzip2  > $database-structure-backup.sql.bz2`;
  `/usr/bin/mysqldump $mysql_backup_options --host=$hostname --user=$username --password='$password' $database | bzip2 > $database-data-backup.sql.bz2`;
  $oss->upload_file_by_file(ossBucket, ossPath($prefix, $database."-structure-backup.sql.bz2"), "$database-structure-backup.sql.bz2");
  $oss->upload_file_by_file(ossBucket, ossPath($prefix, $database."-data-backup.sql.bz2"), "$database-data-backup.sql.bz2");
  
  `rm -rf $database-structure-backup.sql.bz2 $database-data-backup.sql.bz2`;
}

function xtrabackupDBs($database, $username, $password, $xtrabackup, $datadir, $innodb_log_file_size, $prefix, $post_backup_query = '') {
  global $DATE, $oss, $mysql_backup_options;
  
  if (schedule == "hourly") deleteHourlyBackups($prefix);
  
  // Get backup of schema
  `/usr/bin/mysqldump $mysql_backup_options --no-data --host=$hostname --user=$username --password='$password' $database | bzip2  > $database-structure-backup.sql.bz2`;
  $oss->upload_file_by_file(ossBucket, ossPath($prefix, $database."-structure-backup.sql.bz2"), "$database-structure-backup.sql.bz2");

  `rm -rf $database-structure-backup.sql.bz2`;
  
  // Get backup of innodb file
  `$xtrabackup --backup --datadir=$datadir --innodb_log_file_size=$innodb_log_file_size --target=/root/xtrabackup`;
  `$xtrabackup --prepare --datadir=$datadir --innodb_log_file_size=$innodb_log_file_size --target=/root/xtrabackup`;
  `cd /root/xtrabackup && tar jcvf $database-data-backup.tar.bz2 *`;
  $oss->upload_file_by_file(ossBucket, ossPath($prefix, $database."-data-backup.tar.bz2"), "/root/xtrabackup/$database-data-backup.tar.bz2");
  `rm -rf /root/xtrabackup`;
  
  // Connecting, selecting database
  $link = mysql_connect($hostname, $username, $password) or die('Could not connect: ' . mysql_error());
  
  //Run post backup queries if needed
  if ($post_backup_query != '') {
    $result = mysql_query($post_backup_query) or die('Query failed: ' . mysql_error());
  }
  
  // Closing connection
  mysql_close($link);
}

function deleteHourlyBackups($target_prefix) {
  global $oss;

	deleteDailyBackups();
  
  //delete hourly backups, 72 hours before now, except the midnight (00) backup
  $set_date = strtotime('-72 hours');
  if (schedule == "hourly") {
    for ($i = 1; $i <= 23; $i++) {
      $prefix = ossPath('','',$set_date,true).$target_prefix."/".str_pad((string)$i,2,"0",STR_PAD_LEFT)."/";
      if (debug == true) echo("Deleting hourly backup: " . $prefix . "\n");
      deletePrefix($prefix);
    }
  }
}

function deleteWeeklyBackups() {
	global $oss;
	
	//delete the backup from 36 weeks ago
	$set_date = strtotime('-36 weeks');
	$prefix = ossPath('','',$set_date,false);
	
	//only if it wasn't in January
  if ((int)date('n',$set_date) !== 1) {
		if (debug == true) echo "Deleting backup from 36 weeks ago: ".$prefix."\n";

	  //delete each key found
	  deletePrefix($prefix);
	} else {
		if (debug == true) echo "Will NOT delete backup from 36 weeks ago, because that was the January week: ".$prefix."\n";
	}
	
	//delete the backup from 16 weeks ago
	$set_date = strtotime('-16 weeks');
	$prefix = ossPath('','',$set_date,false);
	
	//only if it wasn't the 1st 7 days of the month
  if ((int)date('j',$set_date) > 7) {
	  if (debug == true) echo "Deleting backup from 16 weeks ago: ".$prefix."\n";

		deletePrefix($prefix);
	} else {
		if (debug == true) echo "Will NOT delete backup from 16 weeks ago, because that was the first week: ".$prefix."\n";
	}
}

function deleteDailyBackups() {
  global $oss;
  
  //delete the backup from 2 months ago
  $set_date = strtotime('-2 months');
  
  //only if it wasn't the first of the month or a Saturday
  if ((int)date('j',$set_date) !== 1) {
		//set oss "dir" to delete
	  $prefix = ossPath('','',$set_date,false);

	  if (debug == true) echo "Deleting backup from 2 months ago: ".$prefix."\n";

	  //delete each key found
	  deletePrefix($prefix);
	}
  
  //delete the backup from 2 weeks ago
	$set_date = strtotime('-2 weeks');
	
	//only if it wasn't a saturday or the 1st
  if ((int)date('j',$set_date) !== 1 && (string)date('l',$set_date) !== "Saturday") {
		$prefix = ossPath('','',$set_date,false);
	  if (debug == true) echo "Deleting backup from 2 weeks ago: ".$prefix."\n";

		deletePrefix($prefix);
	}
		
}

function deletePrefix($prefix) {
  global $oss;
  
  //find files to delete
  $response = $oss->list_object(ossBucket, array('prefix' => $prefix,) );
  $response_body = simplexml_load_string($response->body);

  echo $response_body->Name;
  $keys = $response_body->Contents;
  $key = 0;
  foreach ($keys as $meta) {
    if ($key > 0) {
      if (debug == true) echo $meta->Key."\n";
      $oss->delete_object(ossBucket, $meta->Key);
    }
    $key++;
  }
}

function ossPath($prefix, $name, $timestamp = null, $force_hourly = null) {
  if (is_null($timestamp)) $timestamp = time();
  $prefix = $prefix? '/'.$prefix: '/';
  $date = date("Y/m/d",$timestamp);
  if (is_null($force_hourly) && schedule == "hourly") {
    return ossFolder.'/'.$date.$prefix.date('H',$timestamp).'/'.$name;
  } else{
    return ossFolder.'/'.$date.$prefix.$name;
  }
}

?>
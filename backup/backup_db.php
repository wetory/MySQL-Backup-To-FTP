<?php
include_once("includes/functions.php"); 

// Load configuration
$config = parse_ini_file('config.ini');

// Generate backup of database
$db_backup = backup_db($config['db_host'], $config['db_usr'], $config['db_pwd'], $config['db_name'], $config['db_tables']);   

// Create local backup folder if not exists
$folder = 'temp/';
if (!is_dir($folder)) {
    mkdir($folder, 0777, true);
    chmod($folder, 0777);
}

// Construct file name based on database name name and timestamp
$filename = $config['db_name']."_".date('m-d-Y-H-i', time()).'.sql';
    
// Write database backup to file
$handle = fopen($folder.'/'.$filename,'w+');
fwrite($handle, $db_backup);
fclose($handle);
    
// Compress dump file using GZIP
$gzfile = $filename.'.gz';
$gzipped = gzopen ($folder.$gzfile, 'w9');
gzwrite ($gzipped, file_get_contents($folder.$filename));
gzclose($gzipped);
    
// Remove uncompressed .sql file
if (file_exists($folder.$filename)) {
    unlink($folder.$filename);
}

// Create FTP URI based on configuration
$ftp_uri = 'ftp://'.$config['ftp_usr'].':'.$config['ftp_pwd'].'@'.$config['ftp_host'].'/'.$config['ftp_folder'].'/';

// Upload GZIPed file to FTP server 
$local_file = $folder.$gzfile;
$ftp_file = $gzfile;    
uploadToFtp($ftp_uri, $local_file, $ftp_file);

writeToLog('Backup operation successful.');

<?php

/**
 * Create dump of given database
 * @param string $host Database server
 * @param string $user Database user
 * @param string $pass Database user password
 * @param string $db Database name
 * @param string $tables List of tables, by default all tables - "*"
 * @return string
 */
function backup_db($host, $user, $pass, $db, $tables = "*") {

    // Create & check connection
    $link = mysqli_connect($host, $user, $pass, $db);
    if (mysqli_connect_errno()) {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
        exit;
    }
    writeToLog('Connected to database ' . $db . ' on server ' .  $host);

    mysqli_query($link, "SET NAMES 'utf8'");

    // Get all of the tables
    if ($tables == '*') {
        $tables = array();
        $result = mysqli_query($link, 'SHOW TABLES');
        while ($row = mysqli_fetch_row($result)) {
            $tables[] = $row[0];
        }
    } else {
        $tables = is_array($tables) ? $tables : explode(',', $tables);
    }
    writeToLog('Found ' . sizeof($tables) . ' tables in database ' .  $db);
    
    $return = '';
    // Iterate through tables
    foreach ($tables as $table) {
        $result = mysqli_query($link, 'SELECT * FROM ' . $table);
        $num_fields = mysqli_num_fields($result);
        $num_rows = mysqli_num_rows($result);

        $return .= 'DROP TABLE IF EXISTS ' . $table . ';';
        $row2 = mysqli_fetch_row(mysqli_query($link, 'SHOW CREATE TABLE ' . $table));
        $return .= "\n\n" . $row2[1] . ";\n\n";
        $counter = 1;

        // Over tables
        for ($i = 0; $i < $num_fields; $i++) {
            // Over rows
            while ($row = mysqli_fetch_row($result)) {
                if ($counter == 1) {
                    $return .= 'INSERT INTO ' . $table . ' VALUES(';
                } else {
                    $return .= '(';
                }

                // Over fields
                for ($j = 0; $j < $num_fields; $j++) {
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    if (isset($row[$j])) {
                        $return .= '"' . $row[$j] . '"';
                    } else {
                        $return .= '""';
                    }
                    if ($j < ($num_fields - 1)) {
                        $return .= ',';
                    }
                }

                if ($num_rows == $counter) {
                    $return .= ");\n";
                } else {
                    $return .= "),\n";
                }
                ++$counter;
            }
        }
        $return .= "\n\n\n";
        
        writeToLog('Table ' . $table . ' - ' . $num_rows . ' rows processed' );
    }
    return $return;
}

/**
 * Upload file from application server to FTP server defined by connection string
 * @param Resource $ftp_uri URI pointing to folder FTP server using some credentials. Example: ftp://username:password@subdomain.example.com/path1/path2/
 * @param string $ftp_folder Path to folder on FTP server
 * @param File $source Source file
 * @param File $destination Destination file
 * @param number $backup_retention Retention policy for stored backups - by default 3 days
 */
function uploadToFtp($ftp_uri, $source, $destination, $backup_retention = 3) {
    
    // Split FTP URI into:
    // $match[0] = ftp://username:password@sld.domain.tld/path1/path2/
    // $match[1] = ftp://
    // $match[2] = username
    // $match[3] = password
    // $match[4] = sld.domain.tld
    // $match[5] = /path1/path2/
    preg_match("/(ftp:\/\/)(.*?):(.*?)@(.*?)(\/.*)/i", $ftp_uri, $match); 
    
    // Connect to FTP, login, set passive mode and move to folder
    $conn = ftp_connect($match[4]) or die ("Cannot connect to ".$match[1] . $match[4] . $match[5]); 
    ftp_login($conn, $match[2], $match[3]) or die("Cannot login");    
    ftp_pasv($conn, true);
    ftp_chdir($conn, $match[5]) or die("Cannot change directory"); 
    
    // Remove old backups - days given by backup retention 
    $old_backups = ftp_nlist($conn, ".");
    
    writeToLog('Delete old backups:');
    $count = 0;
    foreach ($old_backups as $old_backup) {
        $lastchanged = ftp_mdtm($conn, $old_backup);
        if ($lastchanged < time() - 60 * 60 * 24 * $backup_retention) {
            if (ftp_delete($conn, $old_backup)) {
                echo ' - ' . $old_backup . ' removed <br>';
                $count++;
            }
        }
    }
    
    if ($count > 0) {
        writeToLog(' - old backups deleted from FTP backup server');
        writeToLog(' - count of deleted backups - ' . $count);
    } else {
        writeToLog(' - no backups to delete found');
    }

    // Upload source file to destination in $ftp_folder        
    if (ftp_put($conn, $destination, $source, FTP_BINARY)) {
        writeToLog('Upload of backup media to ftp ' . $match[4] . $match[5] . ' complete.');
        // Remove temp file on application server   
        if (file_exists($source)) {
            unlink($source);            
            writeToLog('Temp file '.$source.' cleared.');
        }
        
    } else {
        // Reaction to problem with uploading
        $message = 'Error occured while uploading backup to ' . $conn .
                "\r\n" . ' No backup uploaded to backup FTP server, backup media left on application server!' .
                "\r\n" . 'Need to be fixed ASAP so as to avoid lost backups.' .
                "\r\n\r\n" . 'Do not reply to this email';
        sendNotificationEmail($mesage);
        writeToLog('Backup error - backup FTP server unreachable. Destination: ' . $destination . '. Email notification has been sent. Backup media left on application server.');
    }
    ftp_close($conn);
}

/**
 * Write message to output and to log file on the server
 * @param string $text Message to be written
 */
function writeToLog($text) {
    
    // write message to output
    echo $text . "<br>";
    
    // prepare text for write to log file
    $log = date('m-d-Y-H-i-s', time()) . " : " . $text . "\r\n";
    
    // check if log folder exists
    $folder = 'log/';
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
        chmod($folder, 0777);
    }
    
    // recycle log if its size exceeds 10MB
    if (file_exists($folder.'/log')) {
        $log_size = filesize($folder.'/log');
        if ($log_size > 10485760) {
            unlink($folder.'/log');
            $log_reset = date('m-d-Y-H-i-s', time()) . " : LOG was recreated because it exceeded size 10MB" . "\r\n";
            $log_file = fopen($folder.'/log', 'a');
            fwrite($log_file, $log_reset);
            fclose($log_file);
        }
    }
    
    // write to log file
    $log_file = fopen($folder.'/log', 'a');
    fwrite($log_file, $log);
    fclose($log_file);
}

/**
 * Send email notification 
 * @param type $mesage
 */
function sendNotificationEmail($mesage) {
    
    // load configuration
    $config = parse_ini_file('./config.ini');
    
    // prepare email and send it
    $to = $config['email'];
    $subject = $config['subject'];
    $headers = "From: ".$config['email_from'];
    mail($to, $subject, $message, $headers);
}

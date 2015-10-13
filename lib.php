<?php
// Created by Josh Willcock 2015
// Created for CL Consortium LTD
class debug_log {
    private $logFile;
    private $emailmessage;
    // Add message to log
    public function addMsg($msg){
            $this->emailmessage .= $msg."\n";
        fwrite($this->logFile, $msg."\n");
    }
    // Creates and opens new file
    public function open(){
        global $CFG;
        $this->logFile = fopen($CFG->debuggingroot.date('Ymd_gi').'_log.txt', "w");
        return true;
    }
    // Closes new file
    public function close(){
        global $CFG;
        $result = fclose($this->logFile);
        if($result==FALSE or $CFG->forcesendemail==TRUE){
            $emailsent = mail($CFG->emailaddress, 'Cron Debug Log: Failed to save: '.$CFG->sitename, $this->emailmessage);
            if($emailsent==false){
                echo 'Unable to send email';
            }else{
            echo 'Failed to save log - email sent to '.$CFG->emailaddress.PHP_EOL;
            }
        }
        return true;
    }
}

class user_sync {
    private $log;
    // Class constructor creates logging object
    public function __construct(debug_log $log){
        $this->log = $log;
    }
    // Finds the Zip - Unzip the file and finds the file returns filename
    private function findFile(){
        global $CFG;
        $zipFileName = glob($CFG->address . $CFG->zipFileName);
        $this->msg('Looking for file: '.$CFG->address . $CFG->zipFileName.'');
        $zip = new ZipArchive;
        $result = $zip->open($zipFileName[0]);
        if ($result === TRUE) {
            $this->msg('Found file beginning unzip ');
            $zip->extractTo($CFG->address);
            $zip->close();
            $this->msg('Unzipped archive successfully');
        }else{
            $this->msg('Unable to locate zipped archive');
            exit;
        }
        $filename = glob($CFG->address . $CFG->extractedFileName);
        $this->msg('Looking for file: '.$CFG->address.$CFG->extractedFileName);
        $this->msg('Found file: '.$filename[0]);
        return $filename[0];
    } // Close find file

    // Outputs debug message to screen
    private function msg($msg){
        global $CFG;
        echo $msg.PHP_EOL;
        if($CFG->debugging){
            $this->log->addMsg($msg);
        }
    } // Close msg

    // Converts CSV to array
    private function csv_to_array($filename){
        global $CFG;
        if(!file_exists($filename) || !is_readable($filename)){
            return FALSE;
        }else{
            $header = NULL;
            $data = array();
            if(($handle = fopen($filename, 'r')) !== FALSE){
                while (($row = fgetcsv($handle, 1000, $CFG->delimiter)) !== FALSE){
                    if(!$header)
                        $header = $row;
                    else
                        $data[] = array_combine($header, $row);
                    }
                    fclose($handle);
                }
                $this->msg('Rows found: '.count($data));
                return $data; 
        }
    } // Close CSV to array

    // Processes the data
    private function processData($data){
        global $CFG;
        $firstuser = true;
        $outcome = new stdClass();
        $outcome->success=0;
        $outcome->failure=0;
        $conn = new mysqli($CFG->host, $CFG->user, $CFG->password, $CFG->dbName);
        if($conn->connect_error){
            die("Connection Failed: ".$conn->connect_error);
        }
        $this->msg('Database Connection Established');
        foreach($data as $user){
            if($firstuser==true){
                $getKeys = array_keys($user);
                $chkcol = mysqli_query($conn, "SELECT * FROM `userimport` LIMIT 1");
                $mycol = mysqli_fetch_array($chkcol, MYSQLI_NUM);
                foreach($getKeys as $key){
                    if(!isset($mycol[$key])){
                        if($mycol[$key]=="username" or $mycol[$key]=="email"){
                            $conn->query("ALTER TABLE `userimport` ADD UNIQUE ".$key." varchar(100) ");
                        }else{
                            $conn->query("ALTER TABLE `userimport` ADD ".$key." varchar(100) ");
                        }
                    }
                }
                $indexraw = mysqli_query("SELECT COUNT(1) IndexIsThere FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema=DATABASE() AND table_name='userimport' AND index_name='userimport'");
                $index = mysqli_fetch_array($indexraw, MYSQLI_NUM);
                if($index->IndexIsThere==0){
                    mysqli_query("ALTER TABLE `userimport` ADD UNIQUE INDEX `Unique` (`username`, `email`)");
                }
                $firstuser = false;
            }
            $queryBuild = 'INSERT INTO `userimport` (';
            $firstkey = true;
            $firstuserkey = true;
            foreach($getKeys as $userkey){
                if($firstkey){
                    $queryBuild .=$userkey;
                    $firstkey = false;
                }else{
                    $queryBuild .=','.$userkey;
                }
            }
            $queryBuild .= ') VALUES (';
            foreach($getKeys as $userkey){
                if($firstuserkey){
                    $queryBuild .='"'.$user[$userkey].'"';
                    $firstuserkey = false;
                }else{
                    $queryBuild .=',"'.$user[$userkey].'"';
                }
            }
            $queryBuild .= ') ON DUPLICATE KEY UPDATE `username` = "'.$user['username'].'", `email`="'.$user['email'].'"';
            $result = $conn->query($queryBuild);
            if($result == true){
                $this->msg('User '.$user['username'].' has been created/updated');
                $outcome->success++;
            }else{
                $this->msg('User '.$user['username'].' has failed to create/update');
                $outcome->failure++;
            }
        }
        $conn->close();
        return $outcome;
    } // Close Process Data

    // Master function which sets off individual functions
    public function execute() {
        global $CFG;
        if($CFG->debugging){
            $this->log->open();
        }
        $this->msg('Beginning User Sync For: '.$CFG->sitename);
        $file = $this->findFile();
        $data = $this->csv_to_array($file);
        if($data === FALSE){
            $this->msg('Cannot read file & extract data');
        }else{
            $outcome = $this->processData($data);
            $this->msg('Total Success: '.$outcome->success);
            $this->msg('Total Failure: '.$outcome->failure);
        }
        if($CFG->debugging){
            $this->log->close();
        }
    } // Close execute function
} // Close Class
?>
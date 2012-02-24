<?php

//	user parameters: kindle name, sending address, zotero user ID, 
//		zotero API key, Zotero search tag, local cache path, path to PHPMailer, path to SimplePie

$kindle_address = 'user@kindle.com'; // the recipient's @kindle.com address
$sender_address = 'sender@yourdomain.org'; // approve at http://www.amazon.com/gp/digital/fiona/manage
$zotero_id = '12345'; // zotero user ID integer
$zotero_key = 'XXXXXXXXXXXXXXXXX'; // create at https://zotero.org/settings/keys
$zotero_tag = 'Kindle'; // Zotero items with this tag will be sent to the kindle
$cache_path = 'cache'; // a local cache path for tracking items already sent and temp files

require_once('simplepie/simplepie.inc'); //required for processing zotero API feed easily
require_once("phpmailer/class.phpmailer.php"); // required for sending MIME attachments easily


function get_array($file) // returns an array from a file if it exists;
{
	$arr = array();
	if (file_exists($file)) {
		$content = file_get_contents($file);
		$arr = unserialize($content);
	}
	return $arr;
}

function set_array($array, $file) // stores an array in a file
{
	// keep array under 50 elements by dropping oldest
	if(count($array) > 50)
        $array = array_slice($array,count($array)-50);    
    $str = serialize($array);
	file_put_contents($file, $str);
}

/* writes the file from a URL */
function get_attachment($url, $path)
{
	$fp = fopen($path, 'w'); 
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_FILE, $fp);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects
    $data = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return filesize($path); // return size to check for file    
}

function mail_file( $to, $subject, $message, $from, $fileatt, $replyto="" )
{
	$mail = new PHPMailer();		
	$mail->From = $from;
	$mail->FromName = $from;
	$mail->AddAddress($to);
	$mail->WordWrap = 50;	// set word wrap to 50 characters
	//encode filename to preserve accents, etc.
	$mail->AddAttachment($fileatt, "=?utf-8?B?".base64_encode(basename($fileatt))."?=");   
	$mail->Subject = $subject;
	$mail->Body    = "Dummy HTML message body <b>in bold!</b>";
	$mail->AltBody = "Dummy body in plain text for non-HTML mail clients";
	
	if(!$mail->Send())
	{
	   $log->lwrite('Mailer Error: ' . $mail->ErrorInfo);
	   exit;
	}
}

/**
 * Logging class:
 * - contains lfile, lopen, lclose and lwrite methods
 * - lfile sets path and name of log file
 * - lwrite will write message to the log file
 * - lclose closes log file
 * - first call of the lwrite will open log file implicitly
 * - message is written with the following format: hh:mm:ss (script name) message
 */
class Logging{
    // define default log file
    private $log_file = '/tmp/logfile.log';
    // define file pointer
    private $fp = null;
    // set log file (path and name)
    public function lfile($path) {
        $this->log_file = $path;
    }
    // write message to the log file
    public function lwrite($message) {
        // if file pointer doesn't exist, then open log file
        if (!$this->fp) {
            $this->lopen();
        }
        // define script name
        $script_name = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME);
        // define current time
        $time = date('H:i:s');
        // write current time, script name and message to the log file
        // in case of using on Windows, instead of "\n" use "\r\n"
        fwrite($this->fp, "$time ($script_name) $message\n");
    }
    // close log file (it's always a good idea to close a file when you're done with it)
    public function lclose() {
        fclose($this->fp);
    }
    // open log file
    private function lopen() {
        // define log file path and name
        $lfile = $this->log_file;
        // define the current date (it will be appended to the log file name)
        $today = date('Y-m-d');
        // open log file for writing only; place the file pointer at the end of the file
        // if the file does not exist, attempt to create it
        $this->fp = fopen($lfile . '_' . $today, 'a') or exit("Can't open $lfile!");
    }
}

// initialize logging
$log = new Logging();
// set path and name of log file
$log->lfile($cache_path.'/z-kindle.log');
 
// build and pull feed
$feed = new SimplePie();
$url = 'https://api.zotero.org/users/'.$zotero_id.'/tags/'.$zotero_tag.'/items?key='.$zotero_key;
$feed->set_feed_url($url);
$feed->enable_cache(false); 
$feed->init();
$feed->handle_content_type();
$log->lwrite('https://api.zotero.org/users/'.$zotero_id.'/tags/'.$zotero_tag.'/items?key=XXXXX feed retrieved');

// load IDs of previously sent items
$prev_ids = get_array($cache_path.'/retrieved');

foreach ($feed->get_items() as $item):
	if (!in_array($item->get_id(true), $prev_ids))
	{
	$log->lwrite('new item found: '.$item->get_title());
	// checking for enclosure to find any attached files
		if ($enclosure = $item->get_enclosure())
		{
			$link = $enclosure->get_link();
			$attachment_path = $cache_path.'/'.$item->get_title();
			if (get_attachment($link.'?key='.$zotero_key, $attachment_path)) { //send any attachment	
				echo "Mailing ".$item->get_title();
				mail_file( $kindle_address, "To Kindle", "Test", $sender_address, $attachment_path, "");
				$log->lwrite($item->get_title().' sent to kindle');
				unlink($attachment_path);
			}
		}
		$prev_ids[] = $item->get_id(true);
	}
endforeach;
set_array($prev_ids, $cache_path.'/retrieved');
// close log file
$log->lclose();
echo "Success!";
?>
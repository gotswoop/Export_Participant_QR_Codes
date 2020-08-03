<?php
/**
 * PLUGIN NAME: participant_export_qrcode.php
 * DESCRIPTION: This script provide a list of qrcode image survey from activated survey form. All the image will be named "record_id[underscore]qrcode_hash.png"
 * VERSION: 1.0
 * AUTHOR: Hugo.POTIER@chu-nimes.fr
 *
 * External Specifications:
 * The script should be call with a valid pid tag, if the survey option is not activated in the project the script stop.
 * 
 * Internal Specifications:
 * The script will create a temp directory and generate qr-code image from a event or survey form.
 * A csv file is also create included the column of the partipant list and the id image and qrcode file image.
 * All files are included in a unique zip file and uploaded to user;
 * At the end of the process, all the temporary files are deleted.
 * 
 * Here an test link example:
 * http://localhost/redcap/plugins/participant_export_qrcode.php?pid=14&survey_id=11&event_id=41
 *
 */
define("NL","<br />\n");
error_reporting(E_ALL);
require_once "../redcap_connect.php";

require_once APP_PATH_DOCROOT . "Config/init_project.php";
require_once APP_PATH_DOCROOT . "Surveys/survey_functions.php";
require_once APP_PATH_DOCROOT . "Classes/phpqrcode/qrlib.php";

$inOneHour = date("YmdHis", mktime(date("H")+1,date("i"),date("s"),date("m"),date("d"),date("Y")));
$filename ='';
$indice_img = 0;
$relative_path='';

// Increase memory limit in case needed for intensive processing (i.e. lots of participants)
increaseMemory(2048);

// Temp folder to generate qr_code
//$qrcode_temp_folder = 'qrcode_temp'.mt_rand();
$qrcode_temp_folder = 'qrcode_temp_'.$inOneHour.'_'.generateRandomHash(6);


// Create temp qrcode folder
if (!file_exists ($qrcode_temp_folder)) {
	if (!mkdir($qrcode_temp_folder, 0770)) {
	    die('Failed to create folders...');
	}
}


//set it to writable location, a place for temp generated PNG files
define ("PNG_TEMP_DIR", dirname(__FILE__).DIRECTORY_SEPARATOR.$qrcode_temp_folder.DIRECTORY_SEPARATOR);


// Get enable_participant_identifiers
$q='SELECT enable_participant_identifiers FROM redcap_projects WHERE project_id='.$_GET['pid'];
$result = mysqli_query($conn,$q);
$ligne = mysqli_fetch_assoc($result);
$enable_participant_identifiers = $ligne['enable_participant_identifiers'];

// If no survey id, assume it's the first form and retrieve
if (!isset($_GET['survey_id'])) {
	$_GET['survey_id'] = getSurveyId();
}

// Ensure the survey_id belongs to this project
if (!checkSurveyProject($_GET['survey_id'])) {
	redirect(APP_PATH_WEBROOT . "index.php?pid=" . PROJECT_ID);
}

// If no survey id, assume it's the first form and retrieve
if (isset($_GET['relative_path']) and !empty($_GET['relative_path'])) {
	$relative_path = $_GET['relative_path'];
	if (substr($relative_path,-1) != "\\") {
		$relative_path .= "\\";
	}
	$relative_path = str_replace("\\", "\\\\", $relative_path);
}


## Build drop-down list of surveys/events
// Create drop-down of ALL surveys and, if longitudinal, the events for which they're designated
$surveyEventOptions = array();
// Loop through each event and output each where this form is designated
foreach ($Proj->eventsForms as $this_event_id=>$these_forms) {
	// Loop through forms
	foreach ($these_forms as $form_name) {
		// Ignore if not a survey
		if (!isset($Proj->forms[$form_name]['survey_id'])) continue;
		// Get survey_id
		$this_survey_id = $Proj->forms[$form_name]['survey_id'];
		// If this is the first form and first event, note it as "public survey"
		$public_survey_text = ($Proj->isFirstEventIdInArm($this_event_id) && $form_name == $Proj->firstForm) ? $lang['survey_351']." " : ""; // = "[Enquête initiale]"
		// If longitudinal, add event name
		$event_name = ($longitudinal) ? " - ".$Proj->eventInfo[$this_event_id]['name_ext'] : "";
		// If survey title is blank (because using a logo instead), then insert the instrument name
		$survey_title = ($Proj->surveys[$this_survey_id]['title'] == "") ? $Proj->forms[$form_name]['menu'] : $Proj->surveys[$this_survey_id]['title'];
		// Truncate survey title if too long
		if (strlen($public_survey_text.$survey_title.$event_name) > 70) {
			$survey_title = substr($survey_title, 0, 67-strlen($public_survey_text)-strlen($event_name)) . "...";
		}
		// Add this survey/event as drop-down option
		$surveyEventOptions["$this_survey_id-$this_event_id"] = "$public_survey_text\"$survey_title\"$event_name";
	}
}
$event_id = $_GET['event_id'];
$survey_id = $_GET['survey_id'];
$surveyActiveName = $surveyEventOptions["$survey_id-$event_id"];


// ZIP FILE-----------------------------------------------------------------------------------------------------------------------
// Make sure server has ZipArchive ability (i.e. is on PHP 5.2.0+)
if (!Files::hasZipArchive()) {
	exit('ERROR: ZipArchive is not installed. It must be installed to use this feature.');
}
// Set the target zip file to be saved in the temp dir (set timestamp in filename as 1 hour from now so that it gets deleted automatically in 1 hour)
$target_zip = APP_PATH_TEMP . "{$inOneHour}_pid{$project_id}_".generateRandomHash(6).".zip";
$zip_parent_folder = "QRCode_".substr(str_replace(" ", "", ucwords(preg_replace("/[^a-zA-Z0-9 ]/", "", html_entity_decode($app_title.$surveyActiveName, ENT_QUOTES)))), 0, 70)."_".date("Y-m-d_Hi");
$download_filename = "$zip_parent_folder.zip";
// ZIP FILE-----------------------------------------------------------------------------------------------------------------------

// RECURSIVE DELETE---------------------------------------------------------------------------------------------------------------
function deleteDir($dir) {
$it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
$files = new RecursiveIteratorIterator($it,RecursiveIteratorIterator::CHILD_FIRST);
	foreach($files as $file) {
	    if ($file->isDir()){
	        rmdir($file->getRealPath());
	    } else {
	        unlink($file->getRealPath());
	    }
	}
return rmdir($dir);
}
// RECURSIVE DELETE---------------------------------------------------------------------------------------------------------------


// Retrieve survey info
$q = db_query("select * from redcap_surveys where project_id = $project_id and survey_id = " . $_GET['survey_id']);
foreach (db_fetch_assoc($q) as $key => $value) {
	$$key = trim(html_entity_decode($value, ENT_QUOTES));
}

// Obtain current arm_id
$_GET['event_id'] = getEventId();
$_GET['arm_id'] = getArmId();


function get_record_id($access_code) {
global $conn;
	$q="SELECT r.record FROM redcap_surveys_participants p, redcap_surveys_response r WHERE p.survey_id =".$_GET['survey_id']." AND r.participant_id = p.participant_id AND p.event_id =".$_GET['event_id']." AND p.access_code = '".$access_code."'";
	$result = mysqli_query($conn,$q);
	$row = mysqli_fetch_assoc($result);
	return $row['record'];
}

// Gather participant list (with identfiers and if Sent/Responded)
$part_list = REDCap::getParticipantList($Proj->surveys[$_GET['survey_id']]['form_name'], $_GET['event_id']);

//processing form input
//remember to sanitize user input in real-life solution !!!
$errorCorrectionLevel = 'H';
if (isset($_GET['level']) && in_array($_GET['level'], array('L','M','Q','H')))
	$errorCorrectionLevel = $_GET['level'];    

// Resolution of qr-code
$matrixPointSize = 2;
if (isset($_GET['size']))
	$matrixPointSize = min(max((int)$_GET['size'], 1), 10);


if (isset($part_list) and !empty($part_list)) {
	$i=1; // image indice
	$num_row = count($part_list);
	foreach ($part_list as $row){
		if (!empty($row['survey_access_code'])){
		$id_img  = ($enable_participant_identifiers == '1') ? get_record_id($row['survey_access_code']) : $i++;
		$filename = PNG_TEMP_DIR.$id_img."_".$row['survey_access_code'].'.png';
        QRcode::png($row['survey_link'], $filename, $errorCorrectionLevel, $matrixPointSize, 2); // A DECOMMENTER POUR METTRE EN SERVICE
		}
	}
}

// Adding id_img and qrcode_name file to array
for($i=0;$i<count($part_list);$i++) {
	$part_list[$i]['id_img'] = ($enable_participant_identifiers == '1') ? get_record_id($part_list[$i]['survey_access_code']) : $i+1;
	$part_list[$i]['qrcode_name'] = $part_list[$i]['id_img'].'_'.$part_list[$i]['survey_access_code'].'.png';
}

// Add headers for CSV file
$headers = array($lang['control_center_56'], $lang['survey_69']); // "Adresse e-mail","Identifiants des participants (s'il y en a)"
if ($twilio_enabled) $headers[] = $lang['design_89']; //= "Téléphone"
$headers[] = $lang['global_49']; // = "Enreg."
$headers[] = $lang['survey_46']; // = "Msg envyé?"
$headers[] = $lang['survey_47']; // = "Répondu?"
$headers[] = $lang['survey_628']; // = "Survey Access Code"
$headers[] = $lang['global_90']; // "Lien de l'enquête"
if (isset($surveyQueueEnabled) && $surveyQueueEnabled) $headers[] = $lang['survey_553']; // "Lien vers la file d'attente des enquêtes"
$headers[] = 'id_img'; // identifiant de l'image peut être numéro d'ordre ou le record_id suivant si l'option enable_participant_identifiers est active ou non
$headers[] = 'qrcode_name'; // "nom du fichier du qr-code"

// Begin writing file from query result
//$fp = fopen('php://memory', "x+");

$outfileName = camelCase(html_entity_decode($app_title.$surveyActiveName, ENT_QUOTES)) . "_Participants_" . date("Y-m-d_Hi") . ".csv";
$fp = fopen(PNG_TEMP_DIR.$outfileName, 'x+') or die('Error creating file');

if ($fp)
{
	// Write headers to file
	fputcsv($fp, $headers);

	// Set values for this row and write to file
	foreach ($part_list as $row)
	{
		// Remove attr not needed here
		unset($row['email_occurrence'], $row['invitation_send_time']);
		// Convert boolean to text
		$row['invitation_sent_status'] = ($row['invitation_sent_status'] == '1') ? $lang['design_100'] : $lang['design_99']; // "Oui" : "Non"
		$row['qrcode_name'] = $relative_path.$row['qrcode_name'];
		switch ($row['response_status']) {
			case '2':
				$row['response_status'] = $lang['design_100']; // "Oui" 
				break;
			case '1':
				$row['response_status'] = $lang['survey_27']; // "Partielle"
				break;
			default:
				$row['response_status'] = $lang['design_99']; // "Non" 
		}
		// Add row to CSV
		fputcsv($fp, $row);
	}

	// adding UTF8 WITH BOM
	//fseek($fp, 0);
	//fwrite($fp,chr(239).chr(187).chr(191));
	fclose($fp);

    // benchmark
    //QRtools::timeBenchmark();
	
}
else
{
	print $lang['global_01']; //= "ERREUR"
}


## CREATE OUTPUT ZIP FILE ---------------------------------------------------------------------------------------------------
if (is_file($target_zip)) unlink($target_zip);

// Create ZipArchive object
$zip = new ZipArchive;

// Start writing to zip file
if ($zip->open($target_zip, ZipArchive::CREATE) === TRUE) {
	// Add each file to archive
	$toc = array();

	// If using WebDAV storage, then connect to WebDAV beforehand
	if ($edoc_storage_option == '1') {
		include APP_PATH_WEBTOOLS . 'webdav/webdav_connection.php';
		$wdc = new WebdavClient();
		$wdc->set_server($webdav_hostname);
		$wdc->set_port($webdav_port); $wdc->set_ssl($webdav_ssl);
		$wdc->set_user($webdav_username);
		$wdc->set_pass($webdav_password);
		$wdc->set_protocol(1); //use HTTP/1.1
		$wdc->set_debug(false);
		if (!$wdc->open()) {
			exit($lang['global_01'].': '.$lang['file_download_11']); // = "ERREUR : N'a pas pu ouvrir la connexion au serveur"
		}
		if (substr($webdav_path,-1) != '/') {
			$webdav_path .= '/';
		}
	// If using S3 storage, then connect to Amazon beforehand
	} elseif ($edoc_storage_option == '2') {
		$s3 = new S3($amazon_s3_key, $amazon_s3_secret, SSL); if (isset($GLOBALS['amazon_s3_endpoint']) && $GLOBALS['amazon_s3_endpoint'] != '') $s3->setEndpoint($GLOBALS['amazon_s3_endpoint']);
	}

	// Loop through files
//	foreach ($docs as &$params){
	foreach ($part_list as $row){
		// Set name of file to be placed in zip file
		$name = $row['qrcode_name'];
		// If not using local storage for edocs, then obtain file contents before adding to zip
		if ($edoc_storage_option == '0' || $edoc_storage_option == '3') {
			// LOCAL: Add from "edocs" folder (use default or custom path for storage)
			if (file_exists(EDOC_PATH . $row['qrcode_name'])) {
				// Make sure file exists first before adding it, otherwise it'll cause it all to fail if missing
				$zip->addFile(EDOC_PATH . $row['qrcode_name'], "$zip_parent_folder/$name");
			}
		} elseif ($edoc_storage_option == '2') {
			// S3
			// Open connection to create file in memory and write to it
			if (($s3->getObject($amazon_s3_bucket, $row['qrcode_name'], APP_PATH_TEMP . $row['qrcode_name'])) !== false) {
				// Make sure file exists first before adding it, otherwise it'll cause it all to fail if missing
				if (file_exists(APP_PATH_TEMP . $row['qrcode_name'])) {
					// Get file's contents from temp directory and add file contents to zip file
					$zip->addFromString("$zip_parent_folder/$name", file_get_contents(APP_PATH_TEMP . $row['qrcode_name']));
					// Now remove file from temp directory
					unlink(APP_PATH_TEMP . $row['qrcode_name']);
				}
			}
		} else {
			// WebDAV
			$contents = '';
			$wdc->get($webdav_path . $row['qrcode_name'], $contents); //$contents is produced by webdav class
			// Add file contents to zip file
			if ($contents == null) $contents = '';
			$zip->addFromString("$zip_parent_folder/$name", $contents);
			
			
		}
		// Add qr-code file to zip
		$zip->addFile(PNG_TEMP_DIR . $row['qrcode_name'], "$zip_parent_folder/$name");
		
	}
	//Add csv file to zip
	$zip->addFile(PNG_TEMP_DIR . $outfileName, "$outfileName");
	
	// Set text for Instructions.txt file
	$readme = "This zip file contain each qr-code for a unique event.
To identify each qr-code, the file was named like '[record_id]_[survey_access_code].png'.
You can also find a csv file that contain an id_img and the list of each qr-code image file name.
You may need it to mail merge variable images with microsoft® word.
You can print on a sticker or on wristband to get direct access to the \"good\" patient form.

IMPORTANT:
The mail merge word process need to have the exact path to find your images.
You need to add in front of the image file name a path like this (with double antislash):
C:\\folder\\to\\my\\picture\\list\\image.png

You can find the entire process into this tutorial video:
---------------------------------------------------------
English:
https://www.youtube.com/watch?v=8wYDVngcpg0
or step-by-step
http://onmerge.com/articleIncludePicture.html

French:
https://www.youtube.com/watch?v=JtwRflkNtSs

Tips: If you don't want that everybody get connected to the form you can limit the access with a REDCap survey login.";
	// Add Instructions.txt to zip file
	$zip->addFromString("Instructions.txt", $readme);
	// Done adding to zip file
	$zip->close();
}
## ERROR
else
{
	exit("ERROR: Unable to create ZIP archive at $target_zip");
}

	// Logging
	log_event("","redcap_edocs_metadata","MANAGE",$_GET['survey_id'],"survey_id = {$_GET['survey_id']}\narm_id = {$_GET['arm_id']}","Download ZIP of qrcode survey participant list");


// Download file and then delete it from the server
header('Pragma: anytextexeptno-cache', true);
header('Content-Type: application/octet-stream"');
header('Content-Disposition: attachment; filename="'.$download_filename.'"');
header('Content-Length: ' . filesize($target_zip));
ob_end_flush();
readfile_chunked($target_zip);
unlink($target_zip);
deleteDir(PNG_TEMP_DIR); // A DECOMMENTER POUR SUPPRIMER LE DOSSIER TEMPORAIRE

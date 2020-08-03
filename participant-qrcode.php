<?php
/**
 * PLUGIN NAME: participant_qrcode.php
 * DESCRIPTION: This script provide an GUI to set parameters to participant list of qrcode image survey from activated survey form. All the image will be named "record_id[underscore] (Compatible with REDCap >= 9.8.0)
 * VERSION: 2.0
 * AUTHOR: Hugo.POTIER@chu-nimes.fr
 *
 * External Specifications:
 * The script should be call with a valid pid tag, if the survey option is not activated in the project the script redirect to project home page.
 *
 * 
 * Internal Specifications:
 * Transmit parameters to participant_export_qrcode.php to generate survey participant qrcode images
 *
 * Here a link with parameters to get zip file with image
 * http://localhost/redcap/plugins/participant_export_qrcode.php?pid=14&survey_id=11&event_id=41
 *
 */

define("NL","<br />\n");
error_reporting(E_ALL);
require_once "../redcap_connect.php";

require_once APP_PATH_DOCROOT . "Config/init_project.php";
//require_once APP_PATH_DOCROOT . "Surveys/survey_functions.php"; // Version < 9.8.0
require_once APP_PATH_DOCROOT . "Classes/Survey.php"; // Version >= 9.8.0

// If not using a type of project with surveys, then don't allow user to use this page.
if (!$surveys_enabled) redirect(APP_PATH_WEBROOT . "index.php?pid=$project_id");


// If no survey id in URL, then determine what it should be here (first available survey_id)
if (!isset($_GET['survey_id']))
{
	if ($Proj->firstFormSurveyId != null) {
		// Get first form's survey_id
		//$_GET['survey_id'] = getSurveyId(); // Version < 9.8.0
		$_GET['survey_id'] = Survey::getSurveyId(); // Version >= 9.8.0 
	} elseif (!empty($Proj->surveys)) {
		// Surveys exist, but the first form is not a survey. So get the first available survey_id and the first available
		// event (exclude any "deleted"/orphaned survey instruments)
		foreach ($Proj->eventsForms as $these_forms) {
			foreach ($these_forms as $form_name) {
				if (!isset($Proj->forms[$form_name]['survey_id'])) continue;
				$_GET['survey_id'] = $Proj->forms[$form_name]['survey_id'];
				break 2;
			}
		}
		// If first form isn't a survey and user didn't explicity click the Public Survey Link tab, then redirect on to Participant List
		if (!isset($_GET['public_survey']) && !isset($_GET['participant_list']) && !isset($_GET['email_log'])) {
			redirect(PAGE_FULL . "?pid=$project_id&participant_list=1");
		}
	} elseif (empty($Proj->surveys)) {
		// If no surveys have been enabled, then redirect to Online Designer to enable them
		redirect(APP_PATH_WEBROOT . "Design/online_designer.php?pid=$project_id&dialog=enable_surveys");
	}
}


// Ensure the survey_id belongs to this project
//if (!checkSurveyProject($_GET['survey_id'])) { // Version < 9.8.0
if (!Survey::checkSurveyProject($_GET['survey_id'])) { // Version >= 9.8.0 
	redirect(APP_PATH_WEBROOT . "index.php?pid=" . PROJECT_ID);
}

// Get firt field name (record_id?)
function first_field($project_id){
		if (!is_numeric($project_id)) return false;
		// Query metadata table
		$sql = "select field_name from redcap_metadata where project_id = $project_id 
				order by field_order limit 1";
		$q = db_query($sql);
		if ($q && db_num_rows($q) > 0) {
			// Return field name
			return db_result($q, 0);
		} else {
			// Return false is query fails or doesn't exist
			return false;
		}
}
$first_field = first_field($project_id);

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

	// Collect HTML
	$surveyEventDropdown = RCView::select(array('class'=>"x-form-text x-form-field",
		'style'=>'max-width:400px;font-weight:bold;font-size:11px;',
//		'onchange'=>"if(this.value!=''){showProgress(1);var seid = this.value.split('-'); window.location.href = app_path_webroot_full+'plugins/'+'participant_export_qrcode.php?pid=$project_id&survey_id='+seid[0]+'&event_id='+seid[1]+'&level='+$('#level').val();}"),
//		'onchange'=>"if(this.value!=''){var seid = this.value.split('-');",
		'id'=>"surveyParticipant"),
			$surveyEventOptions, $_GET['survey_id']."-".$_GET['event_id'], 500
		);
// OPTIONAL: Display the project header
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

?>
<div id="center" class="col-xs-12 col-sm-8 col-md-9"><script type="text/javascript">
$(function() {
	$('#beginTime, #endTime').datetimepicker({
		onClose: function(){ pageLoad() },
		buttonText: 'Click to select a date', yearRange: '-100:+10', changeMonth: true, changeYear: true, dateFormat: user_date_format_jquery,
		hour: currentTime('h'), minute: currentTime('m'), buttonText: 'Click to select a date/time',
		showOn: 'button', buttonImage: app_path_images+'date.png', buttonImageOnly: true, timeFormat: 'hh:mm', constrainInput: false
	});
});
function pageLoad(event) {
	if (event != null && event.keyCode != 13) {
		return;
	}
	showProgress(1);
	window.location.href=app_path_webroot+page+'?pid='+pid+'&beginTime='+$('#beginTime').val()+'&endTime='+$('#endTime').val()+'&usr='+$('#usr').val()+'&record='+$('#record').val()+'&logtype='+$('#logtype').val();
}
</script>
<div class="projhdr">
  <div style="float:left;">
					<?php /*<img src="<?= APP_PATH_IMAGES ?>documents_arrow.png"> */ ?><i class="fa fa-qrcode"></i> Export participant image QR-Code files</div><br><br></div> <!-- todo create $lang reference -->
This module can export all QR-Code images files from a specific event with csv participant list reference.<br /> 
<!-- todo create $lang reference -->
This could be used to prepare study and print on paper, sticker, wristband to get rapidly the exact form of a record.<br />
You can use the mail merge variable images process with microsoft® word <a href="javascript:;" onClick="$('#partListInstrMore').toggle('fade');" style="text-decoration:underline;"><?php echo $lang['survey_86'] ?></a>.<br/><br/>
<?php
// Get enable_participant_identifiers
$q='SELECT enable_participant_identifiers FROM redcap_projects WHERE project_id='.$_GET['pid'];
$result = mysqli_query($conn,$q);
$ligne = mysqli_fetch_assoc($result);
$enable_participant_identifiers = $ligne['enable_participant_identifiers'];


if($enable_participant_identifiers == '1'){
//
echo "The QR-code file will be named like: [".$first_field."]_[".$lang['survey_628']."].png<br/>
Please note: If you don't want the record_id in front of the file name you need to disable the participant Identifier in the Participant List page.";
}else{
//
echo "Because <i>'Participant Identifier'</i> is disable in <i>'Participant List'</i>, the QR-code file will be named like: [OrderNumber]_[".$lang['survey_628']."].png<br/>
Please note: If you want the record_id in front of the file name you need to enable the participant Identifier in the Participant List page.";
}
?>
<br />
<div id="partListInstrMore" style="display:none;margin-top:15px;">
<b>IMPORTANT:</b><br />
The mail merge word process need to have the exact path to find your images.<br />
You can add the image relative path name on the "Path for mail merge image" field.<br />
You can find the entire process into this tutorial video:<a href="https://www.youtube.com/watch?v=8wYDVngcpg0" target="_blank">English youtube video</a> or <a href="https://www.youtube.com/watch?v=JtwRflkNtSs" target="_blank">French youtube video</a><br />
or step-by-step: <a href="http://onmerge.com/articleIncludePicture.html" target="_blank">English instructions</a><br /><br />
Tips: To avoid subject to get connect to the form, you can add a survey login process, only known by clinical research team.<br />
  </div>
<div><br />
		<table border="0" cellpadding="0" cellspacing="0"><tbody><tr>
		  <td class="blue">
			<table border="0" cellpadding="0" cellspacing="3"><tbody><tr>
			<td style="text-align:right;padding-right:5px;"><?= $lang['survey_37']; ?> :
			</td>
			<td><div style='vertical-align:middle;color:#000;font-size:14px;padding:0;font-family:arial;'>
				<span class='wrap'>
				<?= $surveyEventDropdown; ?>
				</span>
				</div>
			</td>
		</tr>
			    <tr>
			      <td style="text-align:right;padding-right:5px;">Error-correcting code:</td><!-- todo create $lang reference -->
			      <td><select id="level">
			        <option value="L">L - smallest</option>
			        <option value="M">M</option>
			        <option value="Q">Q</option>
			        <option value="H" selected="selected">H - best</option>
			        </select></td>
		        </tr>
			    <tr>
			      <td style="text-align:right;padding-right:5px;">QR-Code Size: </td>
			      <!-- todo create $lang reference -->
			      <td><select name="size" id="size">
			        <option value="1">1 (0.87cm) [scan dist. min: 8cm - max: 8cm]</option>
			        <option value="2">2 (1.74 cm) [scan dist. min: 5cm - max: 20cm]</option>
			        <option value="3" selected="selected">3 (2.60 cm) [scan dist. min: 6cm - max: 26cm]</option>
			        <option value="4">4 (3.47 cm) [scan dist. min: 7cm - max: 29cm]</option>
			        <option value="5">5 (4.34 cm) [scan dist. min: 6.5cm - max: 38cm]</option>
			        <option value="6">6 (5.21 cm) [scan dist. min: 8.5cm - max: 58cm]</option>
			        <option value="7">7 (6.08 cm) [scan dist. min: 8.9cm - max: 60.6cm]</option>
			        <option value="8">8 (6.94 cm) [scan dist. min: 9.6cm - max: 69.3cm]</option>
			        <option value="9">9 (7.81 cm) [scan dist. min: 10.3cm - max: 78.1cm]</option>
			        <option value="10">10 (8.68 cm) [scan dist. min: 11.1cm - max: 86.9cm]</option>
					<option value="11">11 (11.90 cm) [scan dist. min: 18cm - max: 100 cm]</option>
					<option value="12">12 (13.00 cm) [scan dist. min: 20cm - max: 200 cm]</option>
					<option value="17">17 (18.50 cm) [scan dist. min: 26cm - max: 300 cm]</option>
					<option value="23">23 (24.00 cm) [scan dist. min: 31cm - max: > 300 cm]</option>
			        </select></td>
		        </tr>
			    <tr>
			      <td style="text-align:right;padding-right:5px;vertical-align:middle;">Path for mail merge image:</td><!-- todo create $lang reference -->
			      <td>
			        <input type="text" id="relative_path" value="" size="77" maxlength="180">
			        <div style='vertical-align:middle;color:#777;font-size:9px;padding:0;font-family:tahoma;'>C:\myfolder\for\mailmerge (facultative)</div></td>
		        </tr>
	</tbody></table>
</td></tr>
</tbody></table>
<br>
<script type="text/javascript">
function redirect(){
	var seid = document.getElementById("surveyParticipant").value.split('-');
	var level = document.getElementById("level").value;
	var size = document.getElementById("size").value;
	var relative_path = document.getElementById("relative_path").value;
	window.location.href=app_path_webroot_full+'plugins/participant_export_qrcode.php?pid=<?= $project_id?>&survey_id='+seid[0]+'&event_id='+seid[1]+'&level='+level+'&size='+size+'&relative_path='+relative_path;
}
</script>
<button class="jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only" onclick="redirect();" role="button"><span class="ui-button-text"><?php /*<img src="<?= APP_PATH_IMAGES ?>folder_zipper.png" style="vertical-align:middle;">*/ ?><i class="fas fa-file-archive"></i> <span style="vertical-align:middle;">Download QR-Code list image</span></span></button><!-- todo create $lang reference -->
</div><br></div>
<?php

// OPTIONAL: Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
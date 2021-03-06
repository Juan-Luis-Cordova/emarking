<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * @package mod
 * @subpackage emarking
 * @copyright 2012 Jorge Villalón {@link http://www.uai.cl}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once (dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once ($CFG->libdir . '/formslib.php');
require_once ($CFG->libdir . '/gradelib.php');
require_once ("$CFG->dirroot/grade/grading/lib.php");
require_once $CFG->dirroot . '/grade/lib.php';
require_once ("$CFG->dirroot/grade/grading/form/rubric/lib.php");
require_once ("$CFG->dirroot/lib/filestorage/file_storage.php");
require_once ($CFG->dirroot . "/mod/emarking/locallib.php");
require_once ($CFG->dirroot . "/mod/emarking/marking/locallib.php");

global $CFG, $DB, $OUTPUT, $PAGE, $USER;

// Required and optional params for ajax interaction in emarking
$ids = required_param('ids', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$pageno = optional_param('pageno', 0, PARAM_INT);
$testingmode = optional_param('testing', false, PARAM_BOOL);

// If we are in testing mode then submission 1 is the only one admitted
if ($testingmode) {
    $username = required_param('username', PARAM_ALPHANUMEXT);
    $password = required_param('password', PARAM_RAW_TRIMMED);
    
    if (! $user = authenticate_user_login($username, $password))
        emarking_json_error('Invalid username or password');
    
    complete_user_login($user);
    
    // Limit testing to submission id 1
    $ids = 1;
}

// If it's just a heartbeat, answer as quickly as possible
if ($action === 'heartbeat') {
    emarking_json_array(array(
        'time' => time()
    ));
    die();
}

// Verify that user is logged in, otherwise return error
if (! isloggedin() && ! $testingmode) {
    emarking_json_error('User is not logged in', array(
        'url' => $CFG->wwwroot . '/login/index.php'
    ));
}

// A valid submission is required
if (! $draft = $DB->get_record('emarking_draft', array(
    'id' => $ids
))) {
    emarking_json_error('Invalid draft');
}

// A valid submission is required
if (! $submission = $DB->get_record('emarking_submission', array(
    'id' => $draft->submissionid
))) {
    emarking_json_error('Invalid submission');
}

// Assignment to which the submission belong
if (! $emarking = $DB->get_record("emarking", array(
    "id" => $draft->emarkingid
))) {
    emarking_json_error('Invalid emarking activity');
}

// The submission's student
$userid = $submission->student;
$ownsubmission = $USER->id == $userid;

// User object for student
if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) {
    $ownsubmission = false;
    $user = null;
} else 
    if (! $user = $DB->get_record('user', array(
        'id' => $userid
    ))) {
        emarking_json_error('Invalid user from submission');
    }

// Progress querys
$totaltest = $DB->count_records_sql("SELECT COUNT(*) from {emarking_draft} WHERE  emarkingid = $emarking->id");
$inprogesstest = $DB->count_records_sql("SELECT COUNT(*) from {emarking_draft} WHERE  emarkingid = $emarking->id AND status = 15");
$publishtest = $DB->count_records_sql("SELECT COUNT(*) from {emarking_draft} WHERE  emarkingid = $emarking->id AND status > 15");

// Agree level query
$agreeRecords = $DB->get_records_sql("
		SELECT d.id, 
		STDDEV(d.grade)*2/6 as dispersion, 
		d.submissionid, 
		COUNT(d.id) as conteo
		FROM {emarking_draft} d
		INNER JOIN {emarking_submission} s ON (s.emarking = $emarking->id AND s.id = d.submissionid)
		INNER JOIN {emarking_page} p ON (p.submission = d.id)
		INNER JOIN {emarking_comment} c ON (c.page= p.id) 
		GROUP BY d.submissionid
		HAVING COUNT(*) > 1");

// Set agree level average of all active grading assignments
if ($agreeRecords) {
    $agreeLevel = array();
    foreach ($agreeRecords as $dispersion) {
        $agreeLevel[] = (float) $dispersion->dispersion;
    }
    $agreeLevelAvg = round(100 * (1 - (array_sum($agreeLevel) / count($agreeLevel))), 1);
} else {
    $agreeLevelAvg = 0;
}

// Set agree level average of current active assignment
$agreeAssignment = $DB->get_record_sql("SELECT d.submissionid, 
										STDDEV(d.grade)*2/6 as dispersion, 
										COUNT(d.id) as conteo
										FROM {emarking_draft} d
										WHERE d.submissionid = ? 
										GROUP BY d.submissionid", array(
    $draft->submissionid
));
if ($agreeAssignment) {
    $agreeAsignmentLevelAvg = $agreeAssignment->dispersion;
} else {
    $agreeAssignmentLevelAvg = 0;
}

// The course to which the assignment belongs
if (! $course = $DB->get_record("course", array(
    "id" => $emarking->course
))) {
    emarking_json_error('Invalid course');
}

// The marking process course module
if (! $cm = get_coursemodule_from_instance("emarking", $emarking->id, $course->id)) {
    emarking_json_error('Invalid emarking course module');
}

// Create the context within the course module
$context = context_module::instance($cm->id);

$usercangrade = has_capability('mod/emarking:grade', $context);
$usercanmanagedelphi = has_capability("mod/emarking:managedelphiprocess", $context);
$usercanregrade = has_capability('mod/emarking:regrade', $context);
$issupervisor = has_capability('mod/emarking:supervisegrading', $context) || is_siteadmin($USER);
$isgroupmode = $cm->groupmode == SEPARATEGROUPS;

$studentanonymous = $emarking->anonymous === "0" || $emarking->anonymous === "1";
if ($ownsubmission || $issupervisor) {
    $studentanonymous = false;
}
$markeranonymous = $emarking->anonymous === "1" || $emarking->anonymous === "3";
if ($issupervisor) {
    $markeranonymous = false;
}

// Get actual user role
$userRole = null;
if ($usercangrade == 1 && $issupervisor == 0) {
    $userRole = "marker";
} else {
    if ($usercangrade == 1 && $issupervisor == 1) {
        $userRole = "teacher";
    }
}

$linkrubric = $emarking->linkrubric;

// readonly by default for security
$readonly = true;
if (($usercangrade && $submission->status >= EMARKING_STATUS_SUBMITTED  && $submission->status < EMARKING_STATUS_PUBLISHED) // If the user can grade and the submission was at least submitted 
    || ($usercanregrade && $submission->status >= EMARKING_STATUS_PUBLISHED) // Once published it requires regrade permissions
    ) { // In markers training the user must be the assigned marker
    $readonly = false;
}

if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING && $draft->teacher != $USER->id) {
    $readonly = true;
}

// Validate grading capability and stop and log unauthorized access
if (! $usercangrade && ! $ownsubmission && ! has_capability('mod/emarking:submit', $context)) {
    $item = array(
        'context' => context_module::instance($cm->id),
        'objectid' => $cm->id
    );
    // Add to Moodle log so some auditing can be done
    \mod_emarking\event\unauthorized_granted::create($item)->trigger();
    emarking_json_error('Unauthorized access!');
}

// $totaltest, $inprogesstest, $publishtest
// Ping action for fast validation of user logged in and communication with server
if ($action === 'ping') {
    
    include "../version.php";
    
    // Start with a default Node JS path, and get the configuration one if any
    $nodejspath = 'http://127.0.0.1:9091';
    if (isset($CFG->emarking_nodejspath)) {
        $nodejspath = $CFG->emarking_nodejspath;
    }
    
    emarking_json_array(array(
        'user' => $USER->id,
        'student' => $userid,
        'username' => $USER->firstname . " " . $USER->lastname,
        'realUsername' => $USER->username, // real username, not name and lastname.
        'role' => $userRole,
        'groupID' => $emarking->id, // emarkig->id assigned to groupID for chat and wall rooms.
        'sesskey' => $USER->sesskey,
        'adminemail' => $CFG->supportemail,
        'cm' => $cm->id,
        'studentanonymous' => $studentanonymous ? "true" : "false",
        'markeranonymous' => $markeranonymous ? "true" : "false",
        'readonly' => $readonly,
        'supervisor' => $issupervisor,
        'managedelphi' => $usercanmanagedelphi,
        'markingtype' => $emarking->type,
        'totalTests' => $totaltest, // Progress bar indicator
        'inProgressTests' => $inprogesstest, // Progress bar indicator
        'publishedTests' => $publishtest, // Progress bar indicator
        'agreeLevel' => $agreeLevelAvg, // General agree bar indicator (avg of all overlapped students).
        'heartbeat' => $emarking->heartbeatenabled,
        'linkrubric' => $linkrubric,
        'collaborativefeatures' => $emarking->collaborativefeatures,
        'coursemodule' => $cm->id,
        'nodejspath' => $nodejspath,
        'motives' => emarking_get_regrade_motives(),
        'version' => $plugin->version
    ));
}

// Now require login so full security is checked
require_login($course->id, false, $cm);

$url = new moodle_url('/mod/emarking/ajax/a.php', array(
    'ids' => $ids,
    'action' => $action,
    'pageno' => $pageno
));

// Switch according to action
switch ($action) {
    
    case 'addchatmessage':
        
        include "act/actAddChatMessage.php";
        emarking_json_array($output);
        break;
    
    case 'addcomment':
        
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\addcomment_added::create($item)->trigger();
        
        include "act/actCheckGradePermissions.php";
        include "act/actAddComment.php";
        emarking_json_array($output);
        break;
    
    case 'addmark':
        
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\addmark_added::create($item)->trigger();
        
        include "act/actCheckGradePermissions.php";
        
        include "act/actAddMark.php";
        emarking_json_array($output);
        break;
    
    case 'addregrade':
        
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\addregrade_added::create($item)->trigger();
        
        // include "act/actCheckRegradePermissions.php";
        
        include "act/actRegrade.php";
        emarking_json_array($output);
        break;
    
    case 'deletecomment':
        include "act/actDeleteComment.php";
        break;
    
    case 'deletemark':
        
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\deletemark_deleted::create($item)->trigger();
        
        include "act/actCheckGradePermissions.php";
        
        include "act/actDeleteMark.php";
        emarking_json_array($output);
        break;
    
    case 'finishmarking':
        
        require_once ($CFG->dirroot . '/mod/emarking/marking/locallib.php');
        require_once ($CFG->dirroot . '/mod/emarking/print/locallib.php');
        
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\marking_ended::create($item)->trigger();
        
        include "act/actCheckGradePermissions.php";
        include "qry/getRubricSubmission.php";
        include "act/actFinishMarking.php";
        
        emarking_json_array($output);
        break;
    
    case 'getalltabs':
        if ($ownsubmission) {
            $submission->seenbystudent = 1;
            $submission->timemodified = time();
            $DB->update_record("emarking_submission", $submission);
        }
        $alltabs = emarking_get_all_pages($emarking, $submission, $draft, $studentanonymous, $context);
        emarking_json_resultset($alltabs);
        break;
    
    case 'getnextsubmission':
        
        $nextsubmission = emarking_get_next_submission($emarking, $draft, $context, $user, $issupervisor);
        emarking_json_array(array(
            'nextsubmission' => $nextsubmission
        ));
        break;
    
    case 'getrubric':
        
        include "qry/getRubricSubmission.php";
        emarking_json_resultset($results);
        break;
    
    case 'getstudents':
        
        include "qry/getStudentsInMarking.php";
        emarking_json_resultset($results);
        break;
    
    case 'getsubmission':
        
        include "qry/getSubmissionGrade.php";
        $output = $results;
        $output->coursemodule = $cm->id;
        $output->markerfirstname = $USER->firstname;
        $output->markerlastname = $USER->lastname;
        $output->markeremail = $USER->email;
        $output->markerid = $USER->id;
        
        include "qry/getRubricSubmission.php";
        $output->rubric = $results;
        
        emarking_json_array($output);
        break;
    
    case 'getchathistory':
        include "qry/getChatHistory.php";
        emarking_json_array($output);
        break;
        
    case 'getvaluescollaborativebuttons':
    	include 'qry/getValuesCollaborativeButtons.php';
    	emarking_json_array($output);
    	break;
    
    case 'prevcomments':     
        include "qry/getPreviousCommentsSubmission.php";
        emarking_json_resultset($results);
        break;
    
    case 'rotatepage':
        if (! $issupervisor) {
            emarking_json_error('Invalid access');
        }
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\rotatepage_switched::create($item)->trigger();
        
        list ($imageurl, $anonymousurl, $imgwidth, $imgheight) = emarking_rotate_image($pageno, $submission, $context);
        if (strlen($imageurl) == 0)
            emarking_json_error('Image is empty');
        $output = array(
            'imageurl' => $imageurl,
            'anonymousimageurl' => $anonymousurl,
            'width' => $imgwidth,
            'height' => $imgheight
        );
        emarking_json_array($output);
        break;
    
    case 'sortpages':
        
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\sortpages_switched::create($item)->trigger();
        
        $neworder = required_param('neworder', PARAM_SEQUENCE);
        $neworderarr = explode(',', $neworder);
        if (! emarking_sort_submission_pages($submission, $neworderarr)) {
            emarking_json_error('Error trying to resort pages!');
        }
        $output = array(
            'neworder' => $neworder
        );
        emarking_json_array($output);
        break;
    
    case 'updcomment':
        // Add to Moodle log so some auditing can be done
        $item = array(
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->id
        );
        \mod_emarking\event\updcomment_updated::create($item)->trigger();
        
        include "act/actCheckGradePermissions.php";
        
        include "qry/updComment.php";
        emarking_json_array(array(
            'message' => 'Success!',
            'newgrade' => $newgrade,
            'timemodified' => time()
        ));
        break;
    
    default:
        emarking_json_error('Invalid action!');
}
?>
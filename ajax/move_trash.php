<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

require_login();
$courseid = required_param('courseid', PARAM_INT);
$testid   = required_param('testid', PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/move_trash.php');

$result = ['status' => 'error'];

if (confirm_sesskey()) {
    // Buscar la categoría 'Papelera' correspondiente a este curso
    $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $courseid, 'is_trash' => 1]);
    
    if ($trashcat) {
        $test = $DB->get_record('local_testmanager_tests', ['id' => $testid]);
        if ($test) {
            $test->categoryid = $trashcat->id;
            $DB->update_record('local_testmanager_tests', $test);
            $result['status'] = 'success';
        }
    }
}

echo json_encode($result);
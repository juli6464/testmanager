<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/testmanager/lib.php');

require_login();
$courseid = required_param('courseid', PARAM_INT);
$testid   = required_param('testid', PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/move_trash.php');
require_capability('local/testmanager:manage', context_system::instance());
require_sesskey();

$result = ['status' => 'error', 'message' => ''];

try {
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid], '*', MUST_EXIST);
    $currentcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid], '*', MUST_EXIST);

    if ($currentcat->courseid != $courseid) {
        throw new moodle_exception('invalidrecord', 'error', '', null, 'El test no pertenece a este curso.');
    }

    if ($currentcat->is_trash) {
        $result['status'] = 'success';
        $result['message'] = 'El test ya estaba en la papelera.';
    } else {
        // La papelera destino es la del curso padre de la categoría actual.
        $trashcat = local_testmanager_get_trash_category($currentcat->courseid);

        $DB->update_record('local_testmanager_tests', (object)[
            'id'             => $test->id,
            'categoryid'     => $trashcat->id,
            'origcategoryid' => $currentcat->id,
        ]);

        $result['status'] = 'success';
        $result['message'] = 'Test movido a la papelera del curso.';
        $result['trashcategoryid'] = $trashcat->id;
    }
} catch (\Throwable $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result);

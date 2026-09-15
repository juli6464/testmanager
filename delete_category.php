<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/testmanager/lib.php');

$id      = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
require_capability('local/testmanager:manage', $context);
$PAGE->set_url(new moodle_url('/local/testmanager/delete_category.php', ['id' => $id]));
$PAGE->set_title('Eliminar Categoría');

$category = $DB->get_record('local_testmanager_categories', ['id' => $id], '*', MUST_EXIST);
$returnurl = new moodle_url('/local/testmanager/index.php', ['filtercourse' => $category->courseid]);

if ($category->is_trash == 1) {
    redirect($returnurl, 'No se puede eliminar la papelera del curso. Utilice "Vaciar papelera".',
        null, \core\output\notification::NOTIFY_ERROR);
}

$testids = $DB->get_fieldset_select('local_testmanager_tests', 'id', 'categoryid = ?', [$id]);
$testcount = count($testids);

if (!$confirm) {
    $message = $testcount > 0
        ? "Esta categoría contiene <strong>{$testcount} tests</strong> asociados. Si procede, la categoría, " .
          "sus tests y los cuestionarios de Moodle asociados serán eliminados de forma definitiva."
        : "¿Desea eliminar definitivamente la categoría <strong>" . format_string($category->name) . "</strong>?";

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        $message,
        new moodle_url('/local/testmanager/delete_category.php', ['id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $returnurl
    );
    echo $OUTPUT->footer();
    exit;
}

require_sesskey();

// Los cmid se resuelven antes del borrado, y los módulos se eliminan tras confirmar la transacción.
$cmids = local_testmanager_get_test_cmids($testids);

try {
    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('local_testmanager_tests', ['categoryid' => $id]);
    $DB->delete_records('local_testmanager_categories', ['id' => $id]);
    $transaction->allow_commit();
} catch (Exception $e) {
    redirect($returnurl, 'Error al eliminar la categoría: ' . $e->getMessage(),
        null, \core\output\notification::NOTIFY_ERROR);
}

local_testmanager_delete_quiz_modules($cmids);

redirect($returnurl, 'Categoría y sus tests eliminados correctamente.',
    null, \core\output\notification::NOTIFY_SUCCESS);

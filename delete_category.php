<?php
require_once(__DIR__ . '/../../config.php');

$id      = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/testmanager/delete_category.php', ['id' => $id]));
$PAGE->set_title('Eliminar Categoría');

$category = $DB->get_record('local_testmanager_categories', ['id' => $id], '*', MUST_EXIST);

if ($category->is_trash == 1) {
    redirect(new moodle_url('/local/testmanager/index.php'), 'No se puede eliminar la papelera del sistema.', null, \core\output\notification::NOTIFY_ERROR);
}

$testcount = $DB->count_records('local_testmanager_tests', ['categoryid' => $id]);

if ($testcount > 0 && !$confirm) {
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        "Esta categoría contiene <strong>{$testcount} tests</strong> asociados. Si procede, la categoría y todos sus tests serán eliminados de forma definitiva.",
        new moodle_url('/local/testmanager/delete_category.php', ['id' => $id, 'confirm' => 1]),
        new moodle_url('/local/testmanager/index.php')
    );
    echo $OUTPUT->footer();
    exit;
}

// Eliminación en cascada de tests y categoría
$DB->delete_records('local_testmanager_tests', ['categoryid' => $id]);
$DB->delete_records('local_testmanager_categories', ['id' => $id]);

redirect(new moodle_url('/local/testmanager/index.php'), 'Categoría y sus tests eliminados correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
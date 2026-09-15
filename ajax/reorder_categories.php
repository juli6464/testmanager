<?php
/**
 * AJAX: Persistir el nuevo orden de categorías de un curso tras un drag & drop.
 * Recibe un array JSON de IDs de categorías y actualiza su sortorder en la DB.
 *
 * @package    local_testmanager
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/reorder_categories.php');
require_capability('local/testmanager:manage', context_system::instance());

header('Content-Type: application/json');

global $DB;

if (!confirm_sesskey()) {
    echo json_encode(['status' => 'error', 'message' => 'Sesskey inválido.']);
    exit;
}

$order_json  = required_param('order', PARAM_RAW);
$courseid    = required_param('courseid', PARAM_INT);
$ordered_ids = json_decode($order_json, true);

if (!is_array($ordered_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Formato inválido.']);
    exit;
}

// Validar que el curso existe.
if (!$DB->record_exists('local_testmanager_courses', ['id' => $courseid])) {
    echo json_encode(['status' => 'error', 'message' => 'Curso inválido.']);
    exit;
}

$reordered = 0;
foreach ($ordered_ids as $position => $categoryid) {
    $categoryid = intval($categoryid);
    if ($categoryid <= 0) {
        continue;
    }
    // Solo se reordenan categorías activas (no la papelera) de este mismo curso.
    $updated = $DB->execute(
        "UPDATE {local_testmanager_categories} SET sortorder = ? WHERE id = ? AND courseid = ? AND is_trash = 0",
        [$position + 1, $categoryid, $courseid]
    );
    if ($updated) {
        $reordered++;
    }
}

echo json_encode(['status' => 'ok', 'reordered' => $reordered]);

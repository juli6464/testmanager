<?php
/**
 * AJAX: Persistir el nuevo orden de tests tras un drag & drop.
 * Recibe un array JSON de IDs de tests y actualiza su sortorder en la DB.
 *
 * @package    local_testmanager
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/reorder_tests.php');

header('Content-Type: application/json');

global $DB;

if (!confirm_sesskey()) {
    echo json_encode(['status' => 'error', 'message' => 'Sesskey inválido.']);
    exit;
}

$order_json  = required_param('order', PARAM_RAW);
$categoryid  = required_param('categoryid', PARAM_INT);
$ordered_ids = json_decode($order_json, true);

if (!is_array($ordered_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Formato inválido.']);
    exit;
}

// Validar que la categoría existe y no es papelera
$cat = $DB->get_record('local_testmanager_categories', ['id' => $categoryid, 'is_trash' => 0]);
if (!$cat) {
    echo json_encode(['status' => 'error', 'message' => 'Categoría inválida.']);
    exit;
}

foreach ($ordered_ids as $position => $testid) {
    $testid = intval($testid);
    if ($testid <= 0) continue;
    // Solo actualizar tests que pertenezcan a esta categoría
    $DB->execute(
        "UPDATE {local_testmanager_tests} SET sortorder = ? WHERE id = ? AND categoryid = ?",
        [$position + 1, $testid, $categoryid]
    );
}

echo json_encode(['status' => 'ok', 'reordered' => count($ordered_ids)]);

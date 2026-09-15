<?php
/**
 * AJAX: Devuelve el listado filtrado de tests para el selector "Importar desde Banco de Preguntas".
 *
 * @package    local_testmanager
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/testmanager/lib.php');

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/bank_search.php');
require_capability('local/testmanager:manage', context_system::instance());
require_sesskey();

header('Content-Type: application/json');

$search         = optional_param('search', '', PARAM_TEXT);
$filtercourse   = optional_param('filtercourse', 0, PARAM_INT);
$filtercategory = optional_param('filtercategory', 0, PARAM_INT);

$tests = local_testmanager_search_bank_tests($search, $filtercourse, $filtercategory);

echo json_encode([
    'status' => 'ok',
    'count'  => count($tests),
    'html'   => local_testmanager_render_bank_test_cards($tests),
]);

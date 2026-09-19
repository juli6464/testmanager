<?php
/**
 * AJAX: Añadir las preguntas de uno o varios tests del banco de local_testmanager
 * como slots nuevos de un cuestionario de Moodle YA EXISTENTE.
 *
 * A diferencia de ajax/import_bank.php (que crea un cuestionario nuevo), aquí no se
 * crea ningún quiz ni course_module: las preguntas se añaden al final del quiz que
 * ya se estaba editando (se usa desde el botón inyectado en mod/quiz/edit.php).
 *
 * @package    local_testmanager
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/import_bank_into_quiz.php');
require_capability('local/testmanager:manage', context_system::instance());

header('Content-Type: application/json');

if (!confirm_sesskey()) {
    echo json_encode(['status' => 'error', 'message' => 'Sesskey inválido.']);
    exit;
}

global $DB;

$cmid          = required_param('cmid', PARAM_INT);
$selected_json = required_param('selected_tests', PARAM_RAW);
$selected_ids  = json_decode($selected_json, true);

if (empty($selected_ids) || !is_array($selected_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No se seleccionaron tests.']);
    exit;
}

$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
if (!$cm) {
    echo json_encode(['status' => 'error', 'message' => 'El cuestionario indicado no existe.']);
    exit;
}

$quizid = (int) $cm->instance;
$modcontext = context_module::instance($cm->id);

$imported_count = 0;
$errors = [];

try {
    $transaction = $DB->start_delegated_transaction();

    $maxslot = (int) $DB->get_field_sql(
        "SELECT COALESCE(MAX(slot), 0) FROM {quiz_slots} WHERE quizid = ?", [$quizid]);
    $maxpage = (int) $DB->get_field_sql(
        "SELECT COALESCE(MAX(page), 0) FROM {quiz_slots} WHERE quizid = ?", [$quizid]);
    $targetpage = $maxpage + 1;

    foreach ($selected_ids as $src_testid) {
        $src_testid = intval($src_testid);

        // Mismo criterio que ajax/import_bank.php: el test origen debe existir y no estar en la papelera.
        $srctest = $DB->get_record_sql("
            SELECT t.*, cat.is_trash
              FROM {local_testmanager_tests} t
              JOIN {local_testmanager_categories} cat ON t.categoryid = cat.id
             WHERE t.id = ? AND cat.is_trash = 0
        ", [$src_testid]);

        if (!$srctest) {
            $errors[] = "Test ID $src_testid no encontrado o está en la papelera.";
            continue;
        }

        if (empty($srctest->quizid) || !$DB->record_exists('quiz', ['id' => $srctest->quizid])) {
            $errors[] = "Test '{$srctest->name}' no tiene cuestionario Moodle vinculado.";
            continue;
        }

        if ((int) $srctest->quizid === $quizid) {
            $errors[] = "Test '{$srctest->name}' ya pertenece a este mismo cuestionario.";
            continue;
        }

        $src_slots = $DB->get_records('quiz_slots', ['quizid' => $srctest->quizid], 'slot ASC');
        $addedhere = 0;

        foreach ($src_slots as $src_slot) {
            // La referencia trae el questionbankentryid: es lo que mantiene la pregunta
            // vinculada al banco (con version = NULL se sigue la última versión siempre).
            $src_ref = $DB->get_record('question_references', [
                'component'    => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid'       => $src_slot->id,
            ]);

            if (!$src_ref) {
                continue;
            }

            $maxslot++;

            $new_slot = new stdClass();
            $new_slot->quizid  = $quizid;
            $new_slot->slot    = $maxslot;
            $new_slot->page    = $targetpage;
            $new_slot->maxmark = $src_slot->maxmark;
            $new_slotid = $DB->insert_record('quiz_slots', $new_slot);

            $new_ref = new stdClass();
            $new_ref->usingcontextid      = $modcontext->id;
            $new_ref->component           = 'mod_quiz';
            $new_ref->questionarea        = 'slot';
            $new_ref->itemid              = $new_slotid;
            $new_ref->questionbankentryid = $src_ref->questionbankentryid;
            $new_ref->version             = $src_ref->version;
            $DB->insert_record('question_references', $new_ref);

            $addedhere++;
        }

        if ($addedhere > 0) {
            $imported_count++;
        } else {
            $errors[] = "Test '{$srctest->name}' no tiene preguntas para importar.";
        }
    }

    $transaction->allow_commit();
} catch (\Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error al importar: ' . $e->getMessage()]);
    exit;
}

// Recalcular calificaciones nativas con la API de Moodle 4.x.
try {
    $gradecalculator = \mod_quiz\quiz_settings::create($quizid)->get_grade_calculator();
    $gradecalculator->recompute_quiz_sumgrades();
} catch (\Throwable $ignore) {
}

echo json_encode([
    'status'   => 'ok',
    'imported' => $imported_count,
    'errors'   => $errors,
]);

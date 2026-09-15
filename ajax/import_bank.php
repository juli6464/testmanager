<?php
/**
 * AJAX: Importar tests seleccionados desde el Banco de Preguntas hacia una categoría destino.
 * Copia las referencias de preguntas del quiz origen al quiz destino (o crea uno nuevo).
 *
 * @package    local_testmanager
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/course/lib.php');

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/testmanager/ajax/import_bank.php');

header('Content-Type: application/json');

if (!confirm_sesskey()) {
    echo json_encode(['status' => 'error', 'message' => 'Sesskey inválido.']);
    exit;
}

global $DB, $USER;

$categoryid    = required_param('categoryid', PARAM_INT);
$selected_json = required_param('selected_tests', PARAM_RAW);
$selected_ids  = json_decode($selected_json, true);

if (empty($selected_ids) || !is_array($selected_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No se seleccionaron tests.']);
    exit;
}

// Validar que la categoría destino existe y no es papelera
$destcat = $DB->get_record('local_testmanager_categories', ['id' => $categoryid, 'is_trash' => 0]);
if (!$destcat) {
    echo json_encode(['status' => 'error', 'message' => 'Categoría destino inválida o es la papelera.']);
    exit;
}

$tmcourse = $DB->get_record('local_testmanager_courses', ['id' => $destcat->courseid]);

// Obtener o crear el curso Moodle asociado al curso del plugin
$moodlecourseid = !empty($tmcourse->moodlecourseid) ? intval($tmcourse->moodlecourseid) : 0;
if ($moodlecourseid <= 1 || !$DB->record_exists('course', ['id' => $moodlecourseid])) {
    $coursedata = new stdClass();
    $coursedata->fullname  = $tmcourse ? $tmcourse->name : ('Gestor de Tests - Curso ' . $destcat->courseid);
    $coursedata->shortname = 'TESTMGR_' . $destcat->courseid . '_' . time();
    $coursedata->category  = 1;
    $coursedata->format    = 'topics';
    $newcourse = create_course($coursedata);
    $moodlecourseid = $newcourse->id;
    if ($tmcourse) {
        $DB->set_field('local_testmanager_courses', 'moodlecourseid', $moodlecourseid, ['id' => $tmcourse->id]);
    }
}

$imported_count  = 0;
$errors          = [];

foreach ($selected_ids as $src_testid) {
    $src_testid = intval($src_testid);

    // Obtener el test origen (verificar que no es de la papelera)
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

    // Verificar que el quiz origen existe
    if (empty($srctest->quizid) || !$DB->record_exists('quiz', ['id' => $srctest->quizid])) {
        $errors[] = "Test '{$srctest->name}' no tiene cuestionario Moodle vinculado.";
        continue;
    }

    $src_quizid = intval($srctest->quizid);

    // Crear nombre único para el test copiado
    $newname = $srctest->name;
    $coursecontext = context_course::instance($moodlecourseid);
    $defaultcat = question_get_default_category($coursecontext->id, true);

    // Crear nuevo Quiz en el curso destino
    $quiz = new stdClass();
    $quiz->course              = $moodlecourseid;
    $quiz->name                = $newname;
    $quiz->intro               = 'Importado desde el gestor.';
    $quiz->introformat         = FORMAT_HTML;
    $quiz->timeopen            = 0;
    $quiz->timeclose           = 0;
    $quiz->timemodified        = time();
    $quiz->timecreated         = time();
    $quiz->password            = '';
    $quiz->subnet              = '';
    $quiz->grade               = 10.0;
    $quiz->sumgrades           = 0.0;
    $quiz->questionsperpage    = 1;
    $quiz->navmethod           = 'free';
    $quiz->shuffleanswers      = 1;
    $quiz->preferredbehaviour  = 'deferredfeedback';
    $quiz->attempts            = 0;
    $quiz->grademethod         = 1;
    $quiz->timelimit           = 0;
    $quiz->overduehandling     = 'autosubmit';
    $quiz->graceperiod         = 0;
    $quiz->decimalpoints       = 2;
    $quiz->questiondecimalpoints = -1;
    $quiz->reviewattempt       = 65536;
    $quiz->reviewcorrectness   = 4352;
    $quiz->reviewmarks         = 4352;
    $quiz->reviewspecificfeedback = 4352;
    $quiz->reviewgeneralfeedback  = 4352;
    $quiz->reviewrightanswer      = 4352;
    $quiz->reviewoverallfeedback  = 4352;
    $quiz->showuserpicture     = 0;
    $quiz->showblocks          = 0;
    $quiz->allowofflineattempts = 0;

    $quizid = $DB->insert_record('quiz', $quiz);
    $quiz->id = $quizid;

    // Sección inicial obligatoria
    $DB->insert_record('quiz_sections', [
        'quizid'           => $quizid,
        'firstslot'        => 1,
        'heading'          => '',
        'shufflequestions' => 0
    ]);

    // Agregar course_module
    $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
    $cm = new stdClass();
    $cm->course   = $moodlecourseid;
    $cm->module   = $module->id;
    $cm->instance = $quizid;
    $cm->section  = 0;
    $cm->visible  = 1;
    $cm->visibleold = 1;
    $cmid = add_course_module($cm);
    course_add_cm_to_section($moodlecourseid, $cmid, 0);
    rebuild_course_cache($moodlecourseid, true);

    // Copiar slots del quiz origen
    $src_slots = $DB->get_records('quiz_slots', ['quizid' => $src_quizid], 'slot ASC');
    $new_question_count = 0;

    if (!empty($src_slots)) {
        $new_context = context_module::instance($cmid);

        foreach ($src_slots as $src_slot) {
            // Obtener la question_bank_entry del slot origen
            $src_ref = $DB->get_record('question_references', [
                'component'   => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid'      => $src_slot->id
            ]);

            if (!$src_ref) {
                continue;
            }

            // Nuevo slot en el quiz destino
            $new_slot = new stdClass();
            $new_slot->quizid   = $quizid;
            $new_slot->slot     = $new_question_count + 1;
            $new_slot->page     = 1;
            $new_slot->maxmark  = $src_slot->maxmark;
            $new_slotid = $DB->insert_record('quiz_slots', $new_slot);

            // Nueva referencia apuntando a la misma question_bank_entry
            $new_ref = new stdClass();
            $new_ref->usingcontextid     = $new_context->id;
            $new_ref->component          = 'mod_quiz';
            $new_ref->questionarea       = 'slot';
            $new_ref->itemid             = $new_slotid;
            $new_ref->questionbankentryid = $src_ref->questionbankentryid;
            $new_ref->version            = $src_ref->version;
            $DB->insert_record('question_references', $new_ref);

            $new_question_count++;
        }

        // Recalcular calificaciones
        try {
            $gradecalculator = \mod_quiz\quiz_settings::create($quizid)->get_grade_calculator();
            $gradecalculator->recompute_quiz_sumgrades();
            $finalgrade = $new_question_count > 0 ? floatval($new_question_count) : 10.0;
            $DB->set_field('quiz', 'grade', $finalgrade, ['id' => $quizid]);
            $gradecalculator->update_quiz_maximum_grade($finalgrade);
        } catch (\Throwable $ignore) {}
    }

    // Calcular sortorder para el nuevo test
    $max_sort = $DB->get_field_sql(
        "SELECT COALESCE(MAX(sortorder), 0) FROM {local_testmanager_tests} WHERE categoryid = ?",
        [$categoryid]
    );

    // Registrar el test en la tabla del plugin
    $DB->insert_record('local_testmanager_tests', [
        'categoryid'     => $categoryid,
        'quizid'         => $quizid,
        'name'           => $newname,
        'question_count' => $new_question_count,
        'sortorder'      => $max_sort + 1,
        'timecreated'    => time()
    ]);

    $imported_count++;
}

echo json_encode([
    'status'  => 'ok',
    'imported' => $imported_count,
    'errors'  => $errors
]);

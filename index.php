<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/course_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/category_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/test_form.php');
require_once($CFG->dirroot . '/local/testmanager/lib.php');

require_login();

global $DB;
$dbman = $DB->get_manager();
if (method_exists($dbman, 'reset_caches')) {
    $dbman->reset_caches();
}

// Autocreación de seguridad si las tablas no existen
if (!$dbman->table_exists('local_testmanager_courses')) {
    require_once(__DIR__ . '/db/install.php');
    if (function_exists('xmldb_local_testmanager_install')) {
        xmldb_local_testmanager_install();
    }
} else {
    // Asegurar campos nuevos en tablas existentes
    $table_courses = new xmldb_table('local_testmanager_courses');
    $field_mcid = new xmldb_field('moodlecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
    if (!$dbman->field_exists($table_courses, $field_mcid)) {
        $dbman->add_field($table_courses, $field_mcid);
    }

    $table_tests = new xmldb_table('local_testmanager_tests');
    $field_qid = new xmldb_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
    if (!$dbman->field_exists($table_tests, $field_qid)) {
        $dbman->add_field($table_tests, $field_qid);
    }

    $field_origcat = new xmldb_field('origcategoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'categoryid');
    if (!$dbman->field_exists($table_tests, $field_origcat)) {
        $dbman->add_field($table_tests, $field_origcat);
    }

    // sortorder es necesario para poder reordenar tests y categorías con drag & drop.
    $field_test_sort = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    if (!$dbman->field_exists($table_tests, $field_test_sort)) {
        $dbman->add_field($table_tests, $field_test_sort);
    }

    $table_categories = new xmldb_table('local_testmanager_categories');
    $field_cat_sort = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    if (!$dbman->field_exists($table_categories, $field_cat_sort)) {
        $dbman->add_field($table_categories, $field_cat_sort);
    }
}

// Auto-reparación de cuestionarios huérfanos o con referencias erróneas previas
$orphan_quizzes = $DB->get_records_sql("
    SELECT q.id, q.name, q.course, q.sumgrades 
    FROM {quiz} q
    JOIN {quiz_slots} qs ON qs.quizid = q.id
    GROUP BY q.id, q.name, q.course, q.sumgrades
");
foreach ($orphan_quizzes as $oq) {
    if (!$DB->record_exists('quiz_sections', ['quizid' => $oq->id])) {
        $DB->insert_record('quiz_sections', [
            'quizid' => $oq->id,
            'firstslot' => 1,
            'heading' => '',
            'shufflequestions' => 0
        ]);
    }
    $oqcm = get_coursemodule_from_instance('quiz', $oq->id);
    if ($oqcm) {
        $oqmodcontext = context_module::instance($oqcm->id);
        $oqslots = $DB->get_records('quiz_slots', ['quizid' => $oq->id]);
        if (!empty($oqslots)) {
            $oqslotids = array_keys($oqslots);
            list($insql, $inparams) = $DB->get_in_or_equal($oqslotids);
            $DB->execute("UPDATE {question_references} 
                             SET usingcontextid = ? 
                           WHERE itemid $insql 
                             AND component = 'mod_quiz' 
                             AND questionarea = 'slot' 
                             AND usingcontextid != ?", array_merge([$oqmodcontext->id], $inparams, [$oqmodcontext->id]));
            if (floatval($oq->sumgrades) <= 0) {
                try {
                    \mod_quiz\quiz_settings::create($oq->id)->get_grade_calculator()->recompute_quiz_sumgrades();
                } catch (\Throwable $ignore) {}
            }
        }
    }
}

// Auto-vincular quizid en local_testmanager_tests si estaba en 0
$unlinked_tests = $DB->get_records('local_testmanager_tests', ['quizid' => 0]);
foreach ($unlinked_tests as $ut) {
    $matched_quiz = $DB->get_record('quiz', ['name' => $ut->name], '*', IGNORE_MULTIPLE);
    if ($matched_quiz) {
        $DB->set_field('local_testmanager_tests', 'quizid', $matched_quiz->id, ['id' => $ut->id]);
    }
}

$PAGE->set_context(context_system::instance());
require_capability('local/testmanager:manage', context_system::instance());
$PAGE->set_url(new moodle_url('/local/testmanager/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Banco de Preguntas');
$PAGE->requires->css('/local/testmanager/styles.css');

$action = optional_param('action', '', PARAM_ALPHA);
$testid = optional_param('testid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$filtercourse = optional_param('filtercourse', 0, PARAM_INT);
$autoopenpapelera = optional_param('autoopenpapelera', 0, PARAM_INT);

$indexurl = new moodle_url('/local/testmanager/index.php');

// Lógica para restaurar un test individual desde la papelera
if ($action === 'restoretest' && $testid && confirm_sesskey()) {
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid], '*', MUST_EXIST);
    $trashcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid, 'is_trash' => 1]);

    if (!$trashcat) {
        redirect($indexurl, 'Este test no está en la papelera.', null, \core\output\notification::NOTIFY_ERROR);
    }

    // Preferimos devolverlo a la categoría de la que salió; si ya no existe, a la primera activa del curso.
    $targetcat = null;
    if (!empty($test->origcategoryid)) {
        $targetcat = $DB->get_record('local_testmanager_categories', [
            'id'       => $test->origcategoryid,
            'courseid' => $trashcat->courseid,
            'is_trash' => 0,
        ]);
    }
    if (!$targetcat) {
        $targetcat = $DB->get_record('local_testmanager_categories',
            ['courseid' => $trashcat->courseid, 'is_trash' => 0], '*', IGNORE_MULTIPLE);
    }

    if (!$targetcat) {
        redirect(new moodle_url('/local/testmanager/index.php', ['filtercourse' => $trashcat->courseid]),
            'No hay ninguna categoría activa en este curso a la que restaurar el test.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    $DB->update_record('local_testmanager_tests', (object)[
        'id'             => $test->id,
        'categoryid'     => $targetcat->id,
        'origcategoryid' => 0,
    ]);

    redirect(new moodle_url('/local/testmanager/index.php', ['filtercourse' => $trashcat->courseid]),
        'Test restaurado en la categoría "' . format_string($targetcat->name) . '".',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para vaciar toda la papelera de un curso (borrado definitivo)
if ($action === 'emptytrash' && $courseid && confirm_sesskey()) {
    $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $courseid, 'is_trash' => 1]);
    $redirecturl = new moodle_url('/local/testmanager/index.php', ['filtercourse' => $courseid]);

    if (!$trashcat) {
        redirect($redirecturl, 'Este curso no tiene papelera.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $testids = $DB->get_fieldset_select('local_testmanager_tests', 'id', 'categoryid = ?', [$trashcat->id]);
    $cmids = local_testmanager_get_test_cmids($testids);

    try {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_testmanager_tests', ['categoryid' => $trashcat->id]);
        $transaction->allow_commit();
    } catch (Exception $e) {
        redirect($redirecturl, 'Error al vaciar la papelera: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    local_testmanager_delete_quiz_modules($cmids);

    redirect($redirecturl, 'La papelera ha sido vaciada (' . count($testids) . ' tests eliminados definitivamente).',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar definitivamente un test que ya está en la papelera
if ($action === 'purgetest' && $testid && confirm_sesskey()) {
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid], '*', MUST_EXIST);
    $trashcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid, 'is_trash' => 1]);

    if (!$trashcat) {
        redirect($indexurl, 'Solo se pueden eliminar definitivamente los tests que están en la papelera.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    $redirecturl = new moodle_url('/local/testmanager/index.php', [
        'filtercourse'     => $trashcat->courseid,
        'autoopenpapelera' => $trashcat->courseid,
    ]);

    $cmids = local_testmanager_get_test_cmids([$test->id]);

    try {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_testmanager_tests', ['id' => $test->id]);
        $transaction->allow_commit();
    } catch (Exception $e) {
        redirect($redirecturl, 'Error al eliminar el test: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    local_testmanager_delete_quiz_modules($cmids);

    redirect($redirecturl, 'Test eliminado definitivamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para mover un test a la papelera del curso al que pertenece su categoría padre
if ($action === 'deletetest' && $testid && confirm_sesskey()) {
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid], '*', MUST_EXIST);
    $currentcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid], '*', MUST_EXIST);

    if ($currentcat->is_trash) {
        redirect(new moodle_url('/local/testmanager/index.php', [
            'filtercourse'     => $currentcat->courseid,
            'autoopenpapelera' => $currentcat->courseid,
        ]), 'El test ya se encuentra en la papelera.', null, \core\output\notification::NOTIFY_WARNING);
    }

    // La papelera destino es siempre la del curso padre de la categoría actual.
    $trashcat = local_testmanager_get_trash_category($currentcat->courseid);

    $DB->update_record('local_testmanager_tests', (object)[
        'id'             => $test->id,
        'categoryid'     => $trashcat->id,
        'origcategoryid' => $currentcat->id,
    ]);

    // Volvemos filtrando por el curso y abriendo su papelera para ver el test ya reciclado.
    $redirecturl = new moodle_url('/local/testmanager/index.php', [
        'filtercourse'     => $currentcat->courseid,
        'autoopenpapelera' => $currentcat->courseid,
    ]);
    redirect($redirecturl, 'Test movido a la Papelera del curso correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar el curso y sus categorías/tests asociados
if ($action === 'deletecourse' && $courseid && confirm_sesskey()) {
    $DB->get_record('local_testmanager_courses', ['id' => $courseid], '*', MUST_EXIST);

    $testids = $DB->get_fieldset_sql(
        "SELECT t.id
           FROM {local_testmanager_tests} t
           JOIN {local_testmanager_categories} c ON c.id = t.categoryid
          WHERE c.courseid = ?", [$courseid]);
    $cmids = local_testmanager_get_test_cmids($testids);

    try {
        $transaction = $DB->start_delegated_transaction();
        $categories = $DB->get_records('local_testmanager_categories', ['courseid' => $courseid]);
        foreach ($categories as $cat) {
            $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
        }
        $DB->delete_records('local_testmanager_categories', ['courseid' => $courseid]);
        $DB->delete_records('local_testmanager_courses', ['id' => $courseid]);
        $transaction->allow_commit();
    } catch (Exception $e) {
        redirect($indexurl, 'Error al eliminar el curso: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    local_testmanager_delete_quiz_modules($cmids);

    redirect($indexurl, 'Curso eliminado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar una categoría y sus tests asociados
if ($action === 'deletecategory' && $categoryid && confirm_sesskey()) {
    $cat = $DB->get_record('local_testmanager_categories', ['id' => $categoryid], '*', MUST_EXIST);
    $redirecturl = new moodle_url('/local/testmanager/index.php', ['filtercourse' => $cat->courseid]);

    if ($cat->is_trash) {
        redirect($redirecturl, 'No se puede eliminar la papelera del curso. Utilice "Vaciar papelera".',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    $testids = $DB->get_fieldset_select('local_testmanager_tests', 'id', 'categoryid = ?', [$cat->id]);
    $cmids = local_testmanager_get_test_cmids($testids);

    try {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
        $DB->delete_records('local_testmanager_categories', ['id' => $cat->id]);
        $transaction->allow_commit();
    } catch (Exception $e) {
        redirect($redirecturl, 'Error al eliminar la categoría: ' . $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    local_testmanager_delete_quiz_modules($cmids);

    redirect($redirecturl, 'Categoría "' . format_string($cat->name) . '" y sus ' . count($testids) .
        ' tests eliminados correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$courseform = new \local_testmanager\form\course_form();
if ($data = $courseform->get_data()) {
    $newcourseid = $DB->insert_record('local_testmanager_courses', ['name' => $data->name, 'timecreated' => time()]);
    $DB->insert_record('local_testmanager_categories', ['courseid' => $newcourseid, 'name' => 'Papelera', 'is_trash' => 1, 'timecreated' => time()]);
    redirect($PAGE->url, 'Curso creado exitosamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$catform = new \local_testmanager\form\category_form();
if ($cdata = $catform->get_data()) {
    $DB->insert_record('local_testmanager_categories', ['courseid' => $cdata->courseid, 'name' => $cdata->name, 'is_trash' => 0, 'timecreated' => time()]);
    redirect($PAGE->url, 'Categoría creada con éxito.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$testform = new \local_testmanager\form\test_form();
if ($tdata = $testform->get_data()) {
    $categoryid = isset($tdata->categoryid) ? intval($tdata->categoryid) : 0;

    if ($categoryid <= 0) {
        redirect($PAGE->url, 'Error: No se especificó una categoría válida para guardar el test.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $testname = $tdata->name;

    $catrecord = $DB->get_record('local_testmanager_categories', ['id' => $categoryid], '*', MUST_EXIST);
    $tmcourseid = $catrecord->courseid;
    $tmcourse = $DB->get_record('local_testmanager_courses', ['id' => $tmcourseid]);

    require_once($CFG->dirroot . '/course/lib.php');

    $moodlecourseid = SITEID; // No creamos cursos reales, usamos la portada (SITEID=1)


    $quiz = new stdClass();
    $quiz->course = $moodlecourseid;
    $quiz->name = $testname;
    $quiz->intro = 'Importado desde el gestor.';
    $quiz->introformat = FORMAT_HTML;
    $quiz->timeopen = 0;
    $quiz->timeclose = 0;
    $quiz->timemodified = time();
    $quiz->timecreated = time();
    $quiz->password = '';
    $quiz->subnet = '';
    $quiz->grade = 10.0;
    $quiz->sumgrades = 0.0;
    $quiz->questionsperpage = 1;
    $quiz->navmethod = 'free';
    $quiz->shuffleanswers = 1;
    $quiz->preferredbehaviour = 'deferredfeedback';
    $quiz->attempts = 0;
    $quiz->grademethod = 1;
    $quiz->timelimit = 0;
    $quiz->overduehandling = 'autosubmit';
    $quiz->graceperiod = 0;
    $quiz->decimalpoints = 2;
    $quiz->questiondecimalpoints = -1;
    $quiz->reviewattempt = 65536;
    $quiz->reviewcorrectness = 4352;
    $quiz->reviewmarks = 4352;
    $quiz->reviewspecificfeedback = 4352;
    $quiz->reviewgeneralfeedback = 4352;
    $quiz->reviewrightanswer = 4352;
    $quiz->reviewoverallfeedback = 4352;
    $quiz->showuserpicture = 0;
    $quiz->showblocks = 0;
    $quiz->completionattemptsexhausted = 0;
    $quiz->completionpass = 0;
    $quiz->allowofflineattempts = 0;
    $quiz->reviewmaxmarks = 0;

    $quizid = $DB->insert_record('quiz', $quiz);
    $quiz->id = $quizid;

    // Sección inicial obligatoria en mdl_quiz_sections
    $DB->insert_record('quiz_sections', [
        'quizid' => $quizid,
        'firstslot' => 1,
        'heading' => '',
        'shufflequestions' => 0
    ]);

    $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);

    $cm = new stdClass();
    $cm->course = $moodlecourseid;
    $cm->module = $module->id;
    $cm->instance = $quizid;
    $cm->section = 0;
    $cm->visible = 0; // Oculto en la portada
    $cm->visibleold = 0;

    $cmid = add_course_module($cm);
    course_add_cm_to_section($moodlecourseid, $cmid, 0);
    rebuild_course_cache($moodlecourseid, true);

    $quiz->cmid = $cmid;
    $draftitemid = $tdata->csvfile;
    global $USER;

    $fs = get_file_storage();
    $usercontext = \context_user::instance($USER->id);
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id DESC', false);

    $csvcontent = '';
    foreach ($files as $file) {
        if (!$file->is_directory()) {
            $csvcontent = $file->get_content();
            break;
        }
    }

    require_once($CFG->dirroot . '/question/editlib.php');
    require_once($CFG->libdir . '/questionlib.php');
    $coursecontext = context_course::instance($moodlecourseid);
    $defaultcat = question_get_default_category($coursecontext->id, true);

    $qcat = $DB->get_record('question_categories', ['contextid' => $coursecontext->id, 'name' => $testname]);
    if (!$qcat) {
        $catdata = new stdClass();
        $catdata->name = $testname;
        $catdata->contextid = $coursecontext->id;
        $catdata->info = 'Preguntas para ' . $testname;
        $catdata->infoformat = FORMAT_HTML;
        $catdata->stamp = make_unique_id_code();
        $catdata->parent = $defaultcat ? $defaultcat->id : 0;
        $catdata->sortorder = 999;
        $qcatid = $DB->insert_record('question_categories', $catdata);
    } else {
        $qcatid = $qcat->id;
    }

    require_once($CFG->dirroot . '/mod/quiz/locallib.php');

    $question_count = 0;
    $sumgrades = 0.0;

    if (!empty($csvcontent)) {
        // Limpiar BOM UTF-8 si existe
        $csvcontent = preg_replace('/^\xEF\xBB\xBF/', '', $csvcontent);
        $lines = preg_split("/\r\n|\n|\r/", $csvcontent);
        
        $current_question = null;
        $questions_data = [];

        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            
            // Usar str_getcsv respetando comillas y delimitador ';'
            $data = str_getcsv($line, ';');
            if (empty($data)) continue;

            $marker = trim($data[0], " \t\n\r\0\x0B\"");

            if ($marker === '*') {
                // Si ya teníamos una pregunta en curso, la guardamos
                if ($current_question !== null) {
                    $questions_data[] = $current_question;
                }
                // Iniciar nueva pregunta
                $current_question = [
                    'text' => isset($data[1]) ? trim($data[1]) : '',
                    'options' => []
                ];
            } else if ($marker === '' && $current_question !== null) {
                // Es una opción de respuesta
                if (isset($data[1]) && trim($data[1]) !== '') {
                    $optText = trim($data[1]);
                    
                    // Omitir si el texto limpio de etiquetas HTML está vacío (ej. <p></p>)
                    if (strip_tags($optText) === '') {
                        continue;
                    }

                    // Comprobar si la columna 3 (índice 2) marca la correcta con 'x' o 'X'
                    $is_correct = false;
                    if (isset($data[2]) && strtolower(trim($data[2], " \t\n\r\0\x0B\"")) === 'x') {
                        $is_correct = true;
                    }
                    $current_question['options'][] = [
                        'text' => $optText,
                        'correct' => $is_correct
                    ];
                }
            }
        }
        // Guardar la última pregunta procesada
        if ($current_question !== null) {
            $questions_data[] = $current_question;
        }

        // Iterar sobre cada pregunta estructurada y crearla en Moodle
        foreach ($questions_data as $q_index => $q_data) {
            if (empty($q_data['text']) || empty($q_data['options'])) continue;

            $question = new stdClass();
            $question->qtype = 'multichoice'; // Configurado correctamente como opción múltiple
            $question->name = mb_substr(strip_tags($q_data['text']), 0, 80) ?: 'Pregunta ' . ($question_count + 1);
            $question->questiontext = $q_data['text'];
            $question->questiontextformat = FORMAT_HTML;
            $question->generalfeedback = '';
            $question->generalfeedbackformat = FORMAT_HTML;
            $question->defaultmark = 1.0;
            $question->penalty = 0.3333333;
            $question->stamp = make_unique_id_code();
            $question->timecreated = time();
            $question->timemodified = time();
            $question->createdby = $USER->id;
            $question->modifiedby = $USER->id;

            $qid = $DB->insert_record('question', $question);

            // Registro obligatorio en el Banco de Preguntas (Arquitectura Moodle 4.x)
            $entry = new stdClass();
            $entry->questioncategoryid = $qcatid;
            $entry->idnumber = null;
            $entry->ownerid = $USER->id;
            $entryid = $DB->insert_record('question_bank_entries', $entry);

            $version = new stdClass();
            $version->questionbankentryid = $entryid;
            $version->version = 1;
            $version->status = 'ready';
            $version->questionid = $qid;
            $DB->insert_record('question_versions', $version);

            // Insertar opciones de respuesta en {question_answers}
            $has_correct = false;
            foreach ($q_data['options'] as $opt) {
                $answer = new stdClass();
                $answer->question = $qid;
                $answer->answer = $opt['text'];
                $answer->fraction = $opt['correct'] ? 1.0 : 0.0;
                if ($opt['correct']) {
                    $has_correct = true;
                }
                $answer->feedback = '';
                $answer->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $answer);
            }

            // Si por alguna razón ninguna opción quedó marcada como correcta, marcar la primera por defecto
            if (!$has_correct && !empty($q_data['options'])) {
                $DB->set_field('question_answers', 'fraction', 1.0, ['question' => $qid], 0, 1);
            }

            // Configuración específica de selección múltiple en Moodle
            $qtype_mc = new stdClass();
            $qtype_mc->questionid = $qid;
            $qtype_mc->layout = 0;
            $qtype_mc->single = 1; // 1 = Una sola respuesta correcta
            $qtype_mc->shuffleanswers = 1;
            $qtype_mc->correctfeedback = '';
            $qtype_mc->correctfeedbackformat = FORMAT_HTML;
            $qtype_mc->partiallycorrectfeedback = '';
            $qtype_mc->partiallycorrectfeedbackformat = FORMAT_HTML;
            $qtype_mc->incorrectfeedback = '';
            $qtype_mc->incorrectfeedbackformat = FORMAT_HTML;
            $qtype_mc->answernumbering = 'abc';
            $qtype_mc->shownumcorrect = 0;
            $DB->insert_record('qtype_multichoice_options', $qtype_mc);

            // Slot en el cuestionario
            $slot = new stdClass();
            $slot->quizid = $quiz->id;
            $slot->slot = $question_count + 1;
            $slot->page = 1;
            $slot->maxmark = 1.0; 
            $slotid = $DB->insert_record('quiz_slots', $slot);

            // En Moodle 4.x se requiere usingcontextid = CONTEXT_MODULE para mod_quiz
            $reference = new stdClass();
            $reference->usingcontextid = \context_module::instance($cmid)->id;
            $reference->component = 'mod_quiz';
            $reference->questionarea = 'slot';
            $reference->itemid = $slotid;
            $reference->questionbankentryid = $entryid;
            $reference->version = null;
            $DB->insert_record('question_references', $reference);

            $question_count++;
            $sumgrades += 1.0;
        }

        // Recalcular calificaciones nativas con la API de Moodle 4.x
        $gradecalculator = \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator();
        $gradecalculator->recompute_quiz_sumgrades();

        $finalgrade = $sumgrades > 0 ? $sumgrades : 10.0;
        $DB->set_field('quiz', 'grade', $finalgrade, ['id' => $quiz->id]);
        $gradecalculator->update_quiz_maximum_grade($finalgrade);

        $DB->insert_record('local_testmanager_tests', [
            'categoryid' => $categoryid,
            'quizid' => $quiz->id,
            'name' => $testname,
            'question_count' => $question_count,
            'timecreated' => time()
        ]);
    }

    $redirecturl = new moodle_url('/local/testmanager/index.php', ['filtercourse' => $tmcourseid]);
    redirect($redirecturl, 'Cuestionario nativo creado, categorizado e integrado con éxito.', null, \core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header();
?>

<div class="container-fluid px-4 py-3">
    <!-- Cabecera Superior -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="testmanager-header-title">Banco de <span class="text-accent pl-1 pr-1">Preguntas</span></h2>
        <div class="d-flex align-items-center">
            <form method="get" action="" class="mb-0 mr-3">
                <div class="bg-white border rounded px-3 py-1 shadow-sm text-muted small d-flex align-items-center">
                    <i class="fa fa-filter text-info mr-2"></i> FILTRO CURSOS:
                    <select name="filtercourse" class="border-0 bg-transparent text-dark font-weight-bold ml-1 shadow-none" style="outline: none; cursor: pointer;" onchange="this.form.submit()">
                        <option value="0">Todos los cursos</option>
                        <?php
                        $allcourses = $DB->get_records('local_testmanager_courses');
                        foreach ($allcourses as $c) {
                            $selected = ($filtercourse == $c->id) ? 'selected' : '';
                            echo '<option value="' . $c->id . '" ' . $selected . '>' . format_string($c->name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </form>
            <button class="btn btn-success rounded px-4 text-white font-weight-bold" type="button" data-toggle="modal" data-target="#modalCrearCurso">
                <i class="fa fa-plus mr-1"></i> Crear Curso
            </button>
        </div>
    </div>

    <!-- Barra de Búsqueda y Botón Nueva Categoría -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <form method="get" action="" class="mb-0" style="width: 100%; max-width: 420px;">
            <div class="input-group bg-white rounded border shadow-sm px-3 py-1">
                <div class="input-group-prepend align-items-center border-0 bg-transparent">
                    <i class="fa fa-search text-muted"></i>
                </div>
                <input type="text" name="search" class="form-control border-0 shadow-none" placeholder="Filtrar tests en las categorías..." value="<?php p($search); ?>">
            </div>
        </form>
        <button class="btn btn-light bg-white border rounde px-4 shadow-sm text-dark font-weight-bold" type="button" data-toggle="modal" data-target="#modalNuevaCategoria">
            <i class="fa fa-folder-plus text-info mr-1"></i> Nueva Categoría
        </button>
    </div>

    <!-- Listado de Cursos y Categorías -->
    <?php
    if (!empty($search)) {
        $escapedsearch = $DB->sql_like_escape($search);
        $searchparam = '%' . $escapedsearch . '%';
        
        $sqlcourses = "SELECT DISTINCT c.id, c.name, c.timecreated 
                       FROM {local_testmanager_courses} c
                       JOIN {local_testmanager_categories} cat ON cat.courseid = c.id
                       JOIN {local_testmanager_tests} t ON t.categoryid = cat.id
                       WHERE " . $DB->sql_like('t.name', ':search', false);
                       
        if ($filtercourse > 0) {
            $sqlcourses .= " AND c.id = :filtercourse";
            $courses = $DB->get_records_sql($sqlcourses, ['search' => $searchparam, 'filtercourse' => $filtercourse]);
        } else {
            $courses = $DB->get_records_sql($sqlcourses, ['search' => $searchparam]);
        }
    } else {
        if ($filtercourse > 0) {
            $courses = $DB->get_records('local_testmanager_courses', ['id' => $filtercourse]);
        } else {
            $courses = $DB->get_records('local_testmanager_courses');
        }
    }

    $found_any_results = false;

    // Los modales se construyen por elemento y se vuelcan al final, fuera de las tarjetas,
    // para que Bootstrap los posicione correctamente y para que cada botón "Eliminar"
    // lleve ya su URL definitiva sin depender de JavaScript.
    $deletemodals = '';

    if (empty($courses)) {
        echo '<div class="alert bg-white border text-center py-4 rounded shadow-sm text-muted">No se encontraron tests que coincidan con <strong>"' . s($search) . '"</strong>.</div>';
    } else {
        echo '<div class="testmanager-list-panel">';
        foreach ($courses as $course) {
            $categories = $DB->get_records_sql(
                "SELECT * FROM {local_testmanager_categories} WHERE courseid = ? AND is_trash = 0 ORDER BY sortorder ASC, id ASC",
                [$course->id]);
            // Todo curso lógico debe tener siempre su papelera; se crea si falta (cursos antiguos).
            $trashcat = local_testmanager_get_trash_category($course->id);

            $testsql = "SELECT t.* FROM {local_testmanager_tests} t 
                        JOIN {local_testmanager_categories} c ON t.categoryid = c.id 
                        WHERE c.courseid = :courseid AND c.is_trash = 0";
            $testparams = ['courseid' => $course->id];

            if (!empty($search)) {
                $testsql .= " AND " . $DB->sql_like('t.name', ':search', false);
                $testparams['search'] = '%' . $DB->sql_like_escape($search) . '%';
            }

            $course_tests = $DB->get_records_sql($testsql, $testparams);
            
            if (!empty($search) && empty($course_tests)) {
                continue;
            }

            $found_any_results = true;
            $total_tests = count($course_tests);
            
            $total_questions = 0;
            foreach ($course_tests as $ctest) {
                $total_questions += $ctest->question_count;
            }

            echo '<div class="testmanager-course-block">';

            // Cabecera Principal del Curso
            echo '<div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">';
            echo '<div class="d-flex align-items-center">';
            echo '<button type="button" class="testmanager-collapse-toggle" data-toggle="collapse" ' .
                'data-target="#courseBody-' . $course->id . '" aria-expanded="true" aria-controls="courseBody-' . $course->id . '" ' .
                'title="Contraer/expandir curso"><i class="fa fa-chevron-down"></i></button>';
            echo '<div class="testmanager-icon-box mr-3">';
            echo '<i class="fa fa-folder fa-lg"></i>';
            echo '</div>';
            echo '<div>';
            echo '<h5 class="mb-0 font-weight-bold text-dark">' . format_string($course->name) . ' <span class="badge badge-secondary ml-2">' . $total_tests . ' tests</span></h5>';
            echo '<small class="text-muted">Total: <strong class="ml-1">' . $total_questions . ' preguntas</strong></small>';
            echo '</div></div>';

            echo '<div class="d-flex align-items-center">';

            // --- Papelera del curso ---
            $trashedtests = $DB->get_records('local_testmanager_tests', ['categoryid' => $trashcat->id], 'timecreated DESC');
            $trashedcount = count($trashedtests);
            $trashedquestions = 0;
            foreach ($trashedtests as $tt) {
                $trashedquestions += $tt->question_count;
            }

            $emptytrashurl = new moodle_url('/local/testmanager/index.php', [
                'action'   => 'emptytrash',
                'courseid' => $course->id,
                'sesskey'  => sesskey(),
            ]);

            echo '<button type="button" class="btn btn-outline-success btn-sm rounded px-3 mr-3 testmanager-trash-dropzone" ' .
                'style="text-transform: none; font-size: 12px;" ' .
                'data-courseid="' . $course->id . '" ' .
                'data-toggle="modal" data-target="#modalPapelera-' . $course->id . '" ' .
                'title="Arrastra aquí un test para enviarlo a la papelera">' .
                '<i class="fa fa-trash mr-1"></i> Papelera del Curso ' .
                '<span class="badge badge-light border ml-1">' . $trashedcount . '</span></button>';

            $deletemodals .= local_testmanager_render_trash_modal(
                $course, $trashedtests, $trashedcount, $trashedquestions, $emptytrashurl);

            // --- Eliminar curso ---
            $deletecourseurl = new moodle_url('/local/testmanager/index.php', [
                'action' => 'deletecourse',
                'courseid' => $course->id,
                'sesskey' => sesskey()
            ]);

            echo '<a href="#" class="text-muted" data-toggle="modal" data-target="#modalEliminarCurso-' . $course->id . '" ' .
                'title="Eliminar Curso"><i class="fa fa-times"></i></a>';

            $deletemodals .= local_testmanager_render_confirm_modal(
                'modalEliminarCurso-' . $course->id,
                'Eliminar Curso',
                'fa-exclamation-triangle',
                '¿Está seguro de que desea eliminar el curso <strong class="text-danger ml-1">"' . s($course->name) . '"</strong>?',
                'Atención: este curso contiene <strong class="ml-1">' . $total_tests . ' tests activos</strong> con <strong class="ml-1">' .
                    $total_questions . ' preguntas</strong> y <strong class="ml-1">' . $trashedcount .
                    ' tests en la papelera</strong>. Todas sus categorías, tests y los cuestionarios de Moodle ' .
                    'asociados serán eliminados definitivamente.',
                $deletecourseurl);

            echo '</div>';
            echo '</div>';

            echo '<div id="courseBody-' . $course->id . '" class="collapse show">';

            // Recorrido de Subcategorías
            foreach ($categories as $cat) {
                $cat_test_sql = "SELECT * FROM {local_testmanager_tests} WHERE categoryid = :categoryid";
                $cat_test_params = ['categoryid' => $cat->id];

                if (!empty($search)) {
                    $cat_test_sql .= " AND " . $DB->sql_like('name', ':search', false);
                    $cat_test_params['search'] = '%' . $DB->sql_like_escape($search) . '%';
                }

                $cat_test_sql .= " ORDER BY sortorder ASC, id ASC";

                $tests = $DB->get_records_sql($cat_test_sql, $cat_test_params);

                if (!empty($search) && empty($tests)) {
                    continue;
                }

                $cat_tests_count = count($tests);

                // Para el aviso de borrado hacen falta los totales reales de la categoría,
                // no los que haya dejado visibles el filtro de búsqueda.
                $cat_all_tests = empty($search)
                    ? $tests
                    : $DB->get_records('local_testmanager_tests', ['categoryid' => $cat->id]);
                $cat_total_tests = count($cat_all_tests);
                $cat_questions_count = 0;
                foreach ($cat_all_tests as $t_item) {
                    $cat_questions_count += $t_item->question_count;
                }

                $deletecaturl = new moodle_url('/local/testmanager/index.php', [
                    'action' => 'deletecategory',
                    'categoryid' => $cat->id,
                    'sesskey' => sesskey()
                ]);

                echo '<div class="mb-4 testmanager-category-section" data-catid="' . $cat->id . '" data-courseid="' . $course->id . '">';
                echo '<div class="testmanager-category-block" draggable="true" title="Arrastra para reordenar la categoría">';
                echo '<div class="d-flex justify-content-between align-items-center">';
                echo '<div class="d-flex align-items-center">';
                echo '<i class="fa fa-grip-vertical text-muted mr-2" style="cursor: grab; font-size: 0.85rem;"></i>';
                echo '<div class="testmanager-icon-box testmanager-icon-box-sm mr-3" style="background-color:#fff7ed; color:#f59e0b;">';
                echo '<i class="fa fa-folder-open"></i>';
                echo '</div>';
                echo '<div>';
                echo '<div class="d-flex align-items-center text-dark font-weight-bold" style="font-size: 0.9rem;">' .
                    format_string($cat->name) . ' <span class="badge badge-light border ml-2 text-muted font-weight-normal">' .
                    $cat_tests_count . ' tests</span></div>';
                echo '<div class="testmanager-category-meta">' .
                    '<span>Perteneciente a: <strong class="ml-1">' . format_string($course->name) . '</strong></span>' .
                    '<span class="mx-2">&bull;</span>' .
                    '<span>Total: <strong class="ml-1">' . $cat_questions_count . ' preguntas</strong></span>' .
                    '</div>';
                echo '</div></div>';

                echo '<a href="#" class="text-muted" data-toggle="modal" data-target="#modalEliminarCategoria-' . $cat->id . '" ' .
                    'title="Eliminar Categoría"><i class="fa fa-trash" style="font-size: 0.85rem;"></i></a>';

                $deletemodals .= local_testmanager_render_confirm_modal(
                    'modalEliminarCategoria-' . $cat->id,
                    'Eliminar Categoría',
                    'fa-exclamation-triangle',
                    '¿Está seguro de que desea eliminar la categoría <strong class="text-danger ml-1">"' . s($cat->name) . '"</strong>?',
                    'Atención: esta categoría contiene <strong class="ml-1">' . $cat_total_tests . ' tests</strong> y <strong class="ml-1">' .
                        $cat_questions_count . ' preguntas</strong>. La categoría, sus tests y los cuestionarios de ' .
                        'Moodle asociados serán eliminados definitivamente.',
                    $deletecaturl);
                echo '</div>'; // .d-flex header
                echo '</div>'; // .testmanager-category-block

                echo '<div class="testmanager-tests-list" data-categoryid="' . $cat->id . '" data-courseid="' . $course->id . '">';

                if (empty($tests)) {
                    echo '<div class="text-muted pl-4 mb-2 font-italic small">No hay tests en esta categoría.</div>';
                } else {
                    foreach ($tests as $t) {
                        $deleteurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletetest', 'testid' => $t->id, 'sesskey' => sesskey()]);

                        $cm = null;
                        if (!empty($t->quizid)) {
                            $cm = get_coursemodule_from_instance('quiz', $t->quizid);
                        }
                        if (!$cm) {
                            // Fallback de compatibilidad para tests antiguos
                            $cm = $DB->get_record_sql("SELECT cm.id FROM {course_modules} cm 
                            JOIN {modules} m ON cm.module = m.id 
                            JOIN {quiz} q ON cm.instance = q.id 
                            WHERE m.name = 'quiz' AND q.name = ?", [$t->name], IGNORE_MULTIPLE);
                        }

                        $nativeurl = $cm ? new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]) : '#';

                        echo '<div class="testmanager-item ml-3" draggable="true" data-testid="' . $t->id .
                            '" data-categoryid="' . $cat->id . '">';
                        echo '<div class="d-flex align-items-center">';
                        echo '<i class="fa fa-grip-vertical text-muted mr-3" style="cursor: grab; font-size: 0.85rem;"></i>';
                        echo '<div class="testmanager-icon-box testmanager-icon-box-sm mr-3">';
                        echo '<i class="fa fa-file-alt"></i>';
                        echo '</div>';
                        echo '<div>';

                        if ($cm) {
                            echo '<a href="' . $nativeurl->out(false) . '" class="font-weight-bold text-dark text-decoration-none" style="font-size: 0.9rem;" title="Ver test en Moodle">' . format_string($t->name) . '</a><br>';
                        } else {
                            echo '<span class="font-weight-bold text-dark" style="font-size: 0.9rem;">' . format_string($t->name) . ' (No vinculado a Moodle)</span><br>';
                        }

                        echo '<span class="badge badge-pill badge-light border text-info px-2 mr-2"><i class="fa fa-question-circle mr-1"></i> ' . $t->question_count . ' preguntas</span>';
                        echo '<small class="text-muted" style="font-size: 75%;">Actualizado: ' . date('Y-m-d', $t->timecreated) . '</small>';
                        echo '</div>';
                        echo '</div>';

                        echo '<div class="d-flex align-items-center">';
                        if ($cm) {
                            echo '<a href="' . $nativeurl->out(false) . '" class="text-info mr-3" title="Ir al Cuestionario"><i class="fa fa-external-link-alt"></i></a>';
                        }
                        echo '<a href="#" class="text-muted" data-toggle="modal" data-target="#modalEliminarTest-' . $t->id . '" ' .
                            'title="Eliminar Test"><i class="fa fa-trash"></i></a>';

                        $deletemodals .= local_testmanager_render_confirm_modal(
                            'modalEliminarTest-' . $t->id,
                            'Eliminar Test',
                            'fa-trash',
                            '¿Está seguro de que desea eliminar el test <strong class="text-danger ml-1">"' . s($t->name) . '"</strong>?',
                            null,
                            $deleteurl,
                            'El test saldrá de la categoría "' . s($cat->name) . '" y se moverá a la <strong class="ml-1">Papelera del curso ' .
                                s($course->name) . '</strong>, donde conservará sus ' . $t->question_count .
                                ' preguntas y podrá restaurarlo o eliminarlo definitivamente.');
                        echo '</div>';
                        echo '</div>';
                    }
                }
                echo '</div>'; // .testmanager-tests-list
                echo '</div>'; // .mb-4.testmanager-category-section
            }

            $firstcat = reset($categories);
            $firstcatid = $firstcat ? $firstcat->id : 0;

            echo '<div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">';
            echo '<small class="text-muted" style="text-transform: none;"><i class="fa fa-grip-vertical mr-1"></i> Arrastre un test para reordenar o mover</small>';
            echo '<div>';
            if ($firstcatid) {
                echo '<button class="btn btn-outline-secondary rounded px-4 mr-2 bg-white font-weight-bold btn-abrir-importar" type="button" data-toggle="modal" data-target="#modalImportarTest" data-categoryid="' . $firstcatid . '" style="text-transform: none;"><i class="fa fa-upload mr-1"></i> Importar Test CSV</button>';
                echo '<button class="btn btn-outline-info rounded px-4 bg-white font-weight-bold btn-abrir-banco" type="button" data-toggle="modal" data-target="#modalImportarBanco" data-categoryid="' . $firstcatid . '" style="text-transform: none;"><i class="fa fa-database mr-1"></i> Importar desde Banco</button>';
            }
            echo '</div>';
            echo '</div>';

            echo '</div>'; // #courseBody-{id} (.collapse)
            echo '</div>'; // .testmanager-course-block
        }
        echo '</div>'; // .testmanager-list-panel

        if (!empty($search) && !$found_any_results) {
            echo '<div class="alert bg-white border text-center py-4 rounded shadow-sm text-muted">No se encontraron tests que coincidan con <strong>"' . s($search) . '"</strong>.</div>';
        }
    }
    ?>
</div>

<?php
// Modales de confirmación y papeleras, uno por elemento, con su URL de acción ya resuelta.
echo $deletemodals;
?>

<!-- Modal para Nueva Categoría -->
<div class="modal fade" id="modalNuevaCategoria" tabindex="-1" role="dialog" aria-labelledby="modalNuevaCategoriaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-bottom-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="d-flex align-items-center justify-content-center bg-light rounded p-2 mr-3 text-info" style="width: 40px; height: 40px;">
                        <i class="fa fa-folder-plus fa-lg"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-dark" id="modalNuevaCategoriaLabel">Nueva Categoría</h5>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body pt-3">
                <?php $catform->display(); ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Crear Curso -->
<div class="modal fade" id="modalCrearCurso" tabindex="-1" role="dialog" aria-labelledby="modalCrearCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-bottom-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="d-flex align-items-center justify-content-center rounded p-2 mr-3 text-success" style="width: 40px; height: 40px; background-color: #eaf5ec;">
                        <i class="fa fa-folder-plus fa-lg"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-dark" id="modalCrearCursoLabel">Crear Curso</h5>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body pt-3">
                <?php $courseform->display(); ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Importar Test CSV -->
<div class="modal fade" id="modalImportarTest" tabindex="-1" role="dialog" aria-labelledby="modalImportarTestLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div class="d-flex align-items-center">
                    <div class="icon-container text-info rounded-circle p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background-color: #e6f6f8;">
                        <i class="fa fa-upload"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-dark" id="modalImportarTestLabel">Importar Test CSV</h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3">
                <?php $testform->display(); ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Importar desde Banco de Preguntas -->
<div class="modal fade" id="modalImportarBanco" tabindex="-1" role="dialog" aria-labelledby="modalImportarBancoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div class="d-flex align-items-center">
                    <div class="icon-container text-info rounded p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background-color: #e6f6f8;">
                        <i class="fa fa-database"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-dark" id="modalImportarBancoLabel">Importar Test desde Banco de Preguntas</h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3">
                <?php echo local_testmanager_render_bank_modal_body(); ?>
            </div>
        </div>
    </div>
</div>

<?php
// El JS debe registrarse con js_amd_inline(): RequireJS se carga dentro de $OUTPUT->footer(),
// así que un <script>require(...)</script> escrito antes del footer se ejecuta cuando `require`
// todavía no existe y ningún manejador llega a engancharse.
$PAGE->requires->js_amd_inline("
require(['jquery'], function($) {
    $(document).on('click', '.btn-abrir-importar', function() {
        $('#id_categoryid').val($(this).attr('data-categoryid'));
    });

    // --- Drag & drop: reordenar tests y categorías, y enviar tests a la papelera -------
    var dndDragType = null;       // 'test' | 'category'
    var dndDragId = null;
    var dndDragScopeId = null;    // tests: id de su categoría; categorías: id de su curso

    var clearDropIndicators = function() {
        $('.testmanager-drag-over-top, .testmanager-drag-over-bottom')
            .removeClass('testmanager-drag-over-top testmanager-drag-over-bottom');
        $('.testmanager-drop-ready').removeClass('testmanager-drop-ready');
    };

    var persistTestOrder = function(categoryid) {
        var ids = $('.testmanager-tests-list[data-categoryid=\"' + categoryid + '\"] .testmanager-item').map(function() {
            return $(this).attr('data-testid');
        }).get();
        if (!ids.length) {
            return;
        }
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/reorder_tests.php',
            method: 'POST',
            data: {order: JSON.stringify(ids), categoryid: categoryid, sesskey: M.cfg.sesskey},
            dataType: 'json'
        });
    };

    var persistCategoryOrder = function(courseid) {
        var ids = $('#courseBody-' + courseid + ' > .testmanager-category-section').map(function() {
            return $(this).attr('data-catid');
        }).get();
        if (!ids.length) {
            return;
        }
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/reorder_categories.php',
            method: 'POST',
            data: {order: JSON.stringify(ids), courseid: courseid, sesskey: M.cfg.sesskey},
            dataType: 'json'
        });
    };

    // Reordenar tests: arrastrando una fila sobre otra de su MISMA categoría.
    $(document).on('dragstart', '.testmanager-item', function(e) {
        var row = $(this);
        dndDragType = 'test';
        dndDragId = row.attr('data-testid');
        dndDragScopeId = row.attr('data-categoryid');
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', dndDragId);
        window.setTimeout(function() { row.addClass('testmanager-dragging'); }, 0);
    });

    $(document).on('dragend', '.testmanager-item', function() {
        $(this).removeClass('testmanager-dragging');
        clearDropIndicators();
        if (dndDragType === 'test' && dndDragScopeId) {
            persistTestOrder(dndDragScopeId);
        }
        dndDragType = null;
        dndDragId = null;
        dndDragScopeId = null;
    });

    $(document).on('dragover', '.testmanager-item', function(e) {
        if (dndDragType !== 'test') {
            return;
        }
        var target = $(this);
        if (target.attr('data-testid') === dndDragId || target.attr('data-categoryid') !== dndDragScopeId) {
            return;
        }
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';

        var rect = this.getBoundingClientRect();
        var before = (e.originalEvent.clientY - rect.top) < (rect.height / 2);
        clearDropIndicators();
        target.addClass(before ? 'testmanager-drag-over-top' : 'testmanager-drag-over-bottom');

        var draggingRow = $('.testmanager-item[data-testid=\"' + dndDragId + '\"]');
        if (before) {
            target.before(draggingRow);
        } else {
            target.after(draggingRow);
        }
    });

    $(document).on('drop', '.testmanager-item', function(e) {
        if (dndDragType === 'test') {
            e.preventDefault();
        }
    });

    // Enviar un test a la papelera del curso soltándolo sobre el botón 'Papelera del Curso'.
    $(document).on('dragenter dragover', '.testmanager-trash-dropzone', function(e) {
        if (dndDragType !== 'test') {
            return;
        }
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';
        $(this).addClass('testmanager-drop-ready');
    });

    $(document).on('dragleave', '.testmanager-trash-dropzone', function() {
        $(this).removeClass('testmanager-drop-ready');
    });

    $(document).on('drop', '.testmanager-trash-dropzone', function(e) {
        if (dndDragType !== 'test') {
            return;
        }
        e.preventDefault();
        var zone = $(this).removeClass('testmanager-drop-ready');
        var testid = dndDragId;
        var courseid = zone.attr('data-courseid');
        var row = $('.testmanager-item[data-testid=\"' + testid + '\"]');
        // El test se va a la papelera: al soltar aquí ya no hay que reordenar su categoría de origen.
        dndDragType = null;
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/move_trash.php',
            method: 'POST',
            data: {courseid: courseid, testid: testid, sesskey: M.cfg.sesskey},
            dataType: 'json'
        }).done(function(response) {
            if (response && response.status === 'success') {
                row.fadeOut(150, function() { window.location.reload(); });
            } else {
                require(['core/notification'], function(Notification) {
                    Notification.addNotification({
                        message: (response && response.message) ? response.message : 'No se pudo mover el test a la papelera.',
                        type: 'error'
                    });
                });
            }
        }).fail(function() {
            require(['core/notification'], function(Notification) {
                Notification.addNotification({message: 'Error de comunicación al mover el test.', type: 'error'});
            });
        });
    });

    // Reordenar categorías: se arrastra desde la cabecera (asa), moviendo toda la sección.
    $(document).on('dragstart', '.testmanager-category-block', function(e) {
        e.stopPropagation();
        var block = $(this);
        var section = block.closest('.testmanager-category-section');
        dndDragType = 'category';
        dndDragId = section.attr('data-catid');
        dndDragScopeId = section.attr('data-courseid');
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', dndDragId);
        window.setTimeout(function() { section.addClass('testmanager-dragging'); }, 0);
    });

    $(document).on('dragend', '.testmanager-category-block', function() {
        $(this).closest('.testmanager-category-section').removeClass('testmanager-dragging');
        clearDropIndicators();
        if (dndDragType === 'category' && dndDragScopeId) {
            persistCategoryOrder(dndDragScopeId);
        }
        dndDragType = null;
        dndDragId = null;
        dndDragScopeId = null;
    });

    $(document).on('dragover', '.testmanager-category-section', function(e) {
        if (dndDragType !== 'category') {
            return;
        }
        var target = $(this);
        if (target.attr('data-catid') === dndDragId || target.attr('data-courseid') !== dndDragScopeId) {
            return;
        }
        e.preventDefault();
        e.originalEvent.dataTransfer.dropEffect = 'move';

        var rect = this.getBoundingClientRect();
        var before = (e.originalEvent.clientY - rect.top) < (rect.height / 2);
        clearDropIndicators();
        target.addClass(before ? 'testmanager-drag-over-top' : 'testmanager-drag-over-bottom');

        var draggingSection = $('.testmanager-category-section[data-catid=\"' + dndDragId + '\"]');
        if (before) {
            target.before(draggingSection);
        } else {
            target.after(draggingSection);
        }
    });

    $(document).on('drop', '.testmanager-category-section', function(e) {
        if (dndDragType === 'category') {
            e.preventDefault();
        }
    });

    // --- Importar Test desde Banco de Preguntas ---------------------------------------
    var bankSelectedIds = [];
    var bankSearchTimer = null;

    var bankRefreshCounter = function() {
        var n = bankSelectedIds.length;
        $('#bank-selected-counter').text(n + (n === 1 ? ' test seleccionado' : ' tests seleccionados'));
        $('#bank-import-btn').prop('disabled', n === 0);
    };

    // El resaltado (borde/fondo/tick) es CSS puro sobre :checked (ver .bank-test-card en styles.css):
    // basta con reflejar aquí el estado marcado/desmarcado del checkbox real.
    var bankApplySelectionToList = function() {
        $('#bank-tests-list .bank-test-checkbox').each(function() {
            $(this).prop('checked', bankSelectedIds.indexOf($(this).val()) !== -1);
        });
    };

    var bankFetchTests = function() {
        var params = {
            search: $('#bank-search-input').val(),
            filtercourse: $('#bank-filter-course').val(),
            filtercategory: $('#bank-filter-category').val(),
            sesskey: M.cfg.sesskey
        };
        $('#bank-tests-list').css('opacity', 0.5);
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/bank_search.php',
            method: 'GET',
            data: params,
            dataType: 'json'
        }).done(function(response) {
            if (response && response.status === 'ok') {
                $('#bank-tests-list').html(response.html);
                $('#bank-results-count').text('Lista de Tests (' + response.count + ' encontrados)');
                bankApplySelectionToList();
            }
        }).always(function() {
            $('#bank-tests-list').css('opacity', 1);
        });
    };

    $(document).on('click', '.btn-abrir-banco', function() {
        $('#id_bank_categoryid').val($(this).attr('data-categoryid'));
        // Cada botón abre el selector para una categoría destino distinta: partimos de cero.
        bankSelectedIds = [];
        $('#bank-search-input').val('');
        $('#bank-filter-course').val('0');
        $('#bank-filter-category').val('0').find('option').show();
        bankRefreshCounter();
        bankFetchTests();
    });

    $(document).on('input', '#bank-search-input', function() {
        window.clearTimeout(bankSearchTimer);
        bankSearchTimer = window.setTimeout(bankFetchTests, 300);
    });

    $(document).on('change', '#bank-filter-course', function() {
        var courseid = $(this).val();
        // Solo mostramos en el desplegable las categorías del curso elegido.
        $('#bank-filter-category option').each(function() {
            var optcourse = $(this).attr('data-courseid');
            $(this).toggle(typeof optcourse === 'undefined' || courseid === '0' || optcourse === courseid);
        });
        $('#bank-filter-category').val('0');
        bankFetchTests();
    });

    $(document).on('change', '#bank-filter-category', bankFetchTests);

    $(document).on('change', '.bank-test-checkbox', function() {
        var id = $(this).val();
        var checked = $(this).is(':checked');
        var pos = bankSelectedIds.indexOf(id);
        if (checked && pos === -1) {
            bankSelectedIds.push(id);
        } else if (!checked && pos !== -1) {
            bankSelectedIds.splice(pos, 1);
        }
        bankRefreshCounter();
    });

    $(document).on('click', '#bank-select-all', function(e) {
        e.preventDefault();
        var visible = $('#bank-tests-list .bank-test-checkbox');
        var allChecked = visible.length > 0 && visible.filter(':checked').length === visible.length;
        visible.each(function() {
            var id = $(this).val();
            var pos = bankSelectedIds.indexOf(id);
            if (allChecked) {
                if (pos !== -1) {
                    bankSelectedIds.splice(pos, 1);
                }
            } else if (pos === -1) {
                bankSelectedIds.push(id);
            }
        });
        bankApplySelectionToList();
        bankRefreshCounter();
    });

    $(document).on('click', '#bank-import-btn', function() {
        var button = $(this);
        if (bankSelectedIds.length === 0 || button.prop('disabled')) {
            return;
        }
        button.prop('disabled', true).text('Importando...');
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/import_bank.php',
            method: 'POST',
            data: {
                categoryid: $('#id_bank_categoryid').val(),
                selected_tests: JSON.stringify(bankSelectedIds),
                sesskey: M.cfg.sesskey
            },
            dataType: 'json'
        }).done(function(response) {
            if (response && response.status === 'ok') {
                require(['core/notification'], function(Notification) {
                    var msg = response.imported + (response.imported === 1 ? ' test importado.' : ' tests importados.');
                    if (response.errors && response.errors.length) {
                        msg += ' Incidencias: ' + response.errors.join(' | ');
                    }
                    Notification.addNotification({
                        message: msg,
                        type: response.errors && response.errors.length ? 'warning' : 'success'
                    });
                    window.setTimeout(function() { window.location.reload(); }, 1200);
                });
            } else {
                require(['core/notification'], function(Notification) {
                    Notification.addNotification({
                        message: (response && response.message) ? response.message : 'No se pudo completar la importación.',
                        type: 'error'
                    });
                });
                button.prop('disabled', false).text('Importar');
            }
        }).fail(function() {
            require(['core/notification'], function(Notification) {
                Notification.addNotification({message: 'Error de comunicación al importar.', type: 'error'});
            });
            button.prop('disabled', false).text('Importar');
        });
    });

    // Tras mover un test a la papelera volvemos abriéndola para que se vea ya reciclado.
    // El plugin jQuery .modal() lo aporta el tema (Boost lo carga por AMD), así que
    // esperamos a que esté disponible en lugar de asumir un orden de carga concreto.
    var autoOpenPapelera = " . (int)$autoopenpapelera . ";
    if (autoOpenPapelera > 0) {
        var intentos = 0;
        var abrir = function() {
            var \$modal = $('#modalPapelera-' + autoOpenPapelera);
            if (!\$modal.length) {
                return;
            }
            if (typeof \$modal.modal === 'function') {
                \$modal.modal('show');
            } else if (intentos++ < 40) {
                window.setTimeout(abrir, 100);
            }
        };
        abrir();
    }
});
");

echo $OUTPUT->footer();
?>
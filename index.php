<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/course_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/category_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/test_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/bank_test_form.php');

require_login();
require_capability('local/testmanager:manage', context_system::instance());

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

// Lógica para restaurar un test individual desde la papelera
if ($action === 'restoretest' && $testid && confirm_sesskey()) {
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid]);
    if ($test) {
        $trashcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid, 'is_trash' => 1]);
        if ($trashcat) {
            $firstactivecat = $DB->get_record('local_testmanager_categories', ['courseid' => $trashcat->courseid, 'is_trash' => 0], '*', IGNORE_MULTIPLE);
            if ($firstactivecat) {
                $DB->set_field('local_testmanager_tests', 'categoryid', $firstactivecat->id, ['id' => $testid]);
            }
        }
    }
    redirect(new moodle_url('/local/testmanager/index.php'), 'Test restaurado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para vaciar toda la papelera de un curso
if ($action === 'emptytrash' && $courseid && confirm_sesskey()) {
    $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $courseid, 'is_trash' => 1]);
    if ($trashcat) {
        $DB->delete_records('local_testmanager_tests', ['categoryid' => $trashcat->id]);
    }
    redirect(new moodle_url('/local/testmanager/index.php'), 'La papelera ha sido vaciada.', null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'deletetest' && $testid && confirm_sesskey()) {
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid]);
    if ($test) {
        // Obtener la categoría actual del test para saber a qué curso pertenece
        $currentcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid]);
        if ($currentcat) {
            // Buscar la categoría papelera (is_trash = 1) de ese mismo curso
            $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $currentcat->courseid, 'is_trash' => 1]);
            if ($trashcat) {
                // Mover el test a la papelera cambiando su categoryid
                $DB->set_field('local_testmanager_tests', 'categoryid', $trashcat->id, ['id' => $testid]);
            }
        }
    }
    redirect(new moodle_url('/local/testmanager/index.php'), 'Test movido a la papelera correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar el curso y sus categorías/tests asociados
if ($action === 'deletecourse' && $courseid && confirm_sesskey()) {
    $transaction = $DB->start_delegated_transaction();
    try {
        $categories = $DB->get_records('local_testmanager_categories', ['courseid' => $courseid]);
        foreach ($categories as $cat) {
            $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
        }
        $DB->delete_records('local_testmanager_categories', ['courseid' => $courseid]);
        $DB->delete_records('local_testmanager_courses', ['id' => $courseid]);
        $transaction->allow_commit();
    } catch (Exception $e) {
        $transaction->rollback($e);
    }
    redirect(new moodle_url('/local/testmanager/index.php'), 'Curso eliminado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar una categoría y sus tests asociados
if ($action === 'deletecategory' && $categoryid && confirm_sesskey()) {
    $transaction = $DB->start_delegated_transaction();
    try {
        $cat = $DB->get_record('local_testmanager_categories', ['id' => $categoryid, 'is_trash' => 0]);
        if ($cat) {
            $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
            $DB->delete_records('local_testmanager_categories', ['id' => $cat->id]);
        }
        $transaction->allow_commit();
    } catch (Exception $e) {
        $transaction->rollback($e);
    }
    redirect(new moodle_url('/local/testmanager/index.php'), 'Categoría eliminada correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
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
        <h2 class="testmanager-header-title">Banco de <span class="text-primary pl-1 pr-1">Preguntas</span></h2>
        <div class="d-flex align-items-center">
            <form method="get" action="" class="mb-0 mr-3">
                <div class="bg-white border rounded-pill px-3 py-1 shadow-sm text-muted small d-flex align-items-center">
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
            <button class="btn btn-success rounded-pill px-4 text-white font-weight-bold" type="button" data-toggle="modal" data-target="#modalCrearCurso">
                <i class="fa fa-plus mr-1"></i> Crear Curso
            </button>
        </div>
    </div>

    <!-- Barra de Búsqueda y Botón Nueva Categoría -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-9">
            <form method="get" action="">
                <div class="input-group bg-white rounded-pill border shadow-sm px-3 py-1">
                    <div class="input-group-prepend align-items-center border-0 bg-transparent">
                        <i class="fa fa-search text-muted"></i>
                    </div>
                    <input type="text" name="search" class="form-control border-0 shadow-none" placeholder="Filtrar tests en las categorías..." value="<?php p($search); ?>">
                </div>
            </form>
        </div>
        <div class="col-md-3 text-right">
            <button class="btn btn-light bg-white border rounded-pill px-4 shadow-sm text-dark font-weight-bold" type="button" data-toggle="modal" data-target="#modalNuevaCategoria">
                <i class="fa fa-folder-open text-primary mr-1"></i> Nueva Categoría
            </button>
        </div>
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

    if (empty($courses)) {
        echo '<div class="alert bg-white border text-center py-4 rounded shadow-sm text-muted">No se encontraron tests que coincidan con <strong>"' . s($search) . '"</strong>.</div>';
    } else {
        foreach ($courses as $course) {
            $categories = $DB->get_records_sql("SELECT * FROM {local_testmanager_categories} WHERE courseid = ? AND is_trash = 0", [$course->id]);
            $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $course->id, 'is_trash' => 1]);

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

            echo '<div class="testmanager-course-card mb-4 p-3 bg-white border rounded shadow-sm">';

            // Cabecera Principal del Curso
            echo '<div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">';
            echo '<div class="d-flex align-items-center">';
            echo '<div class="d-flex align-items-center justify-content-center bg-light rounded p-2 mr-3" style="width: 42px; height: 42px; min-width: 42px;">';
            echo '<i class="fa fa-folder text-info fa-lg"></i>';
            echo '</div>';
            echo '<div>';
            echo '<h5 class="mb-0 font-weight-bold text-dark" style="font-size: 1rem !important; text-transform: none !important;">' . format_string($course->name) . ' <span class="badge badge-secondary ml-2">' . $total_tests . ' tests</span></h5>';
            echo '<small class="text-muted">Total: <strong>' . $total_questions . ' preguntas</strong></small>';
            echo '</div></div>';

            echo '<div class="d-flex align-items-center">';
            if ($trashcat) {
                $trashed_tests = $DB->get_records('local_testmanager_tests', ['categoryid' => $trashcat->id]);

                $tests_data = [];
                foreach ($trashed_tests as $tt) {
                    $restoreurl = new moodle_url('/local/testmanager/index.php', ['action' => 'restoretest', 'testid' => $tt->id, 'sesskey' => sesskey()]);
                    $tests_data[] = [
                        'name' => format_string($tt->name),
                        'questions' => $tt->question_count,
                        'date' => date('Y-m-d', $tt->timecreated),
                        'restoreurl' => $restoreurl->out(false)
                    ];
                }

                $emptytrashurl = new moodle_url('/local/testmanager/index.php', ['action' => 'emptytrash', 'courseid' => $course->id, 'sesskey' => sesskey()]);

                echo '<button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 mr-3 btn-abrir-papelera" style="text-transform: none; font-size: 12px;" ' .
                    'data-toggle="modal" data-target="#modalPapeleraCurso" ' .
                    'data-coursename="' . s($course->name) . '" ' .
                    'data-emptyurl="' . $emptytrashurl . '" ' .
                    'data-tests=\'' . json_encode($tests_data) . '\'>' .
                    '<i class="fa fa-trash mr-1"></i> Papelera del Curso</button>';
            }

            $deletecourseurl = new moodle_url('/local/testmanager/index.php', [
                'action' => 'deletecourse',
                'courseid' => $course->id,
                'sesskey' => sesskey()
            ]);

            echo '<a href="#" class="text-muted btn-abrir-modal-curso" data-toggle="modal" data-target="#modalEliminarCurso" ' .
                'data-coursename="' . s($course->name) . '" ' .
                'data-testcount="' . $total_tests . '" ' .
                'data-questioncount="' . $total_questions . '" ' .
                'data-deleteurl="' . $deletecourseurl->out(false) . '"><i class="fa fa-times"></i></a>';
            echo '</div>';
            echo '</div>';

            // Recorrido de Subcategorías
            foreach ($categories as $cat) {
                $cat_test_sql = "SELECT * FROM {local_testmanager_tests} WHERE categoryid = :categoryid";
                $cat_test_params = ['categoryid' => $cat->id];

                if (!empty($search)) {
                    $cat_test_sql .= " AND " . $DB->sql_like('name', ':search', false);
                    $cat_test_params['search'] = '%' . $DB->sql_like_escape($search) . '%';
                }

                $tests = $DB->get_records_sql($cat_test_sql, $cat_test_params);

                if (!empty($search) && empty($tests)) {
                    continue;
                }

                $cat_tests_count = count($tests);
                $cat_questions_count = 0;
                foreach ($tests as $t_item) {
                    $cat_questions_count += $t_item->question_count;
                }

                $deletecaturl = new moodle_url('/local/testmanager/index.php', [
                    'action' => 'deletecategory',
                    'categoryid' => $cat->id,
                    'sesskey' => sesskey()
                ]);

                echo '<div class="mb-3 pl-2">';
                echo '<div class="d-flex justify-content-between align-items-center mb-2 pr-2" style="font-size: 0.9rem;">';
                echo '<div class="d-flex align-items-center text-dark font-weight-bold">';
                echo '<i class="fa fa-folder-open text-warning mr-2"></i> ' . format_string($cat->name) . ' <span class="badge badge-light border ml-2 text-muted font-weight-normal">' . $cat_tests_count . ' tests</span>';
                echo '</div>';

                echo '<a href="#" class="text-muted btn-abrir-modal-categoria" data-toggle="modal" data-target="#modalEliminarCategoria" ' .
                    'data-catname="' . s($cat->name) . '" ' .
                    'data-testcount="' . $cat_tests_count . '" ' .
                    'data-questioncount="' . $cat_questions_count . '" ' .
                    'data-deleteurl="' . $deletecaturl->out(false) . '" title="Eliminar Categoría"><i class="fa fa-trash" style="font-size: 0.85rem;"></i></a>';
                echo '</div>';

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

                        echo '<div class="testmanager-item ml-3 p-2 border rounded mb-2 bg-light d-flex justify-content-between align-items-center" draggable="true">';
                        echo '<div class="d-flex align-items-center">';
                        echo '<i class="fa fa-grip-vertical text-muted mr-3" style="cursor: grab; font-size: 0.85rem;"></i>';
                        echo '<div class="d-flex align-items-center justify-content-center bg-white rounded mr-3 shadow-sm" style="width: 34px; height: 34px; min-width: 34px;">';
                        echo '<i class="fa fa-file-alt text-info"></i>';
                        echo '</div>';
                        echo '<div>';

                        if ($cm) {
                            echo '<a href="' . $nativeurl->out(false) . '" class="font-weight-bold text-dark text-decoration-none" style="font-size: 0.9rem;" title="Ver test en Moodle">' . format_string($t->name) . '</a><br>';
                        } else {
                            echo '<span class="font-weight-bold text-dark" style="font-size: 0.9rem;">' . format_string($t->name) . ' (No vinculado a Moodle)</span><br>';
                        }

                        echo '<span class="badge badge-info mr-2"><i class="fa fa-question-circle mr-1"></i> ' . $t->question_count . ' preguntas</span>';
                        echo '<small class="text-muted" style="font-size: 75%;">Actualizado: ' . date('Y-m-d', $t->timecreated) . '</small>';
                        echo '</div>';
                        echo '</div>';

                        echo '<div class="d-flex align-items-center">';
                        if ($cm) {
                            echo '<a href="' . $nativeurl->out(false) . '" class="text-info mr-3" title="Ir al Cuestionario"><i class="fa fa-external-link-alt"></i></a>';
                        }
                        echo '<a href="#" class="text-muted btn-abrir-modal-test" data-toggle="modal" data-target="#modalEliminarTest" ' .
                            'data-testname="' . s($t->name) . '" ' .
                            'data-deleteurl="' . $deleteurl->out(false) . '" title="Eliminar Test"><i class="fa fa-trash"></i></a>';
                        echo '</div>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            }

            $firstcat = reset($categories);
            $firstcatid = $firstcat ? $firstcat->id : 0;

            echo '<div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">';
            echo '<small class="text-muted" style="text-transform: none;"><i class="fa fa-grip-vertical mr-1"></i> Arrastre un test para reordenar o mover</small>';
            echo '<div>';
            if ($firstcatid) {
                echo '<button class="btn btn-outline-secondary btn-sm rounded-pill px-3 mr-2 bg-white btn-abrir-importar" type="button" data-toggle="modal" data-target="#modalImportarTest" data-categoryid="' . $firstcatid . '" style="text-transform: none; font-size: 12px;"><i class="fa fa-upload mr-1"></i> Importar Test CSV</button>';
                echo '<button class="btn btn-outline-info btn-sm rounded-pill px-3 bg-white btn-abrir-banco" type="button" data-toggle="modal" data-target="#modalImportarBanco" data-categoryid="' . $firstcatid . '" style="text-transform: none; font-size: 12px;"><i class="fa fa-database mr-1"></i> Importar desde Banco</button>';
            }
            echo '</div>';
            echo '</div>';

            echo '</div>';
        }

        if (!empty($search) && !$found_any_results) {
            echo '<div class="alert bg-white border text-center py-4 rounded shadow-sm text-muted">No se encontraron tests que coincidan con <strong>"' . s($search) . '"</strong>.</div>';
        }
    }
    ?>
</div>

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
                    <div class="icon-container text-info rounded-circle p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background-color: #e6f6f8;">
                        <i class="fa fa-database"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-dark" id="modalImportarBancoLabel">Importar Test desde Banco de Preguntas</h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3">
                <?php
                $bankform = new \local_testmanager\form\bank_test_form();
                $bankform->display();
                ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Eliminar Curso -->
<div class="modal fade" id="modalEliminarCurso" tabindex="-1" role="dialog" aria-labelledby="modalEliminarCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4" style="background-color: #fdf2f2;">
                <div class="d-flex align-items-center">
                    <div class="d-flex align-items-center justify-content-center rounded-circle p-2 mr-3 text-danger" style="width: 40px; height: 40px; background-color: #fde8e8;">
                        <i class="fa fa-exclamation-triangle fa-lg"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-danger" id="modalEliminarCursoLabel">Eliminar Curso</h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3" style="background-color: #fdf2f2;">
                <p class="text-dark mb-3" style="font-size: 0.95rem;">
                    ¿Está seguro de que desea eliminar el curso <strong id="modal-curso-nombre" class="text-danger"></strong>?
                </p>
                <div class="alert border border-danger bg-white text-danger rounded p-3 mb-4 small">
                    <i class="fa fa-exclamation-triangle mr-1"></i>
                    Atención: Este curso contiene <strong id="modal-curso-tests" class="pl-1 pr-2">0 tests</strong> y <strong id="modal-curso-preguntas" class="text-danger pl-1">0 preguntas</strong>. Todos los elementos asociados serán removidos definitivamente.
                </div>
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-light border rounded-pill px-4 mr-2 text-dark font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <a href="#" id="btn-confirmar-eliminar-curso" class="btn btn-danger rounded-pill px-4 text-white font-weight-bold" style="background-color: #e53e3e; border-color: #e53e3e;">Eliminar</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Eliminar Categoría -->
<div class="modal fade" id="modalEliminarCategoria" tabindex="-1" role="dialog" aria-labelledby="modalEliminarCategoriaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4" style="background-color: #fdf2f2;">
                <div class="d-flex align-items-center">
                    <div class="d-flex align-items-center justify-content-center rounded-circle p-2 mr-3 text-danger" style="width: 40px; height: 40px; background-color: #fde8e8;">
                        <i class="fa fa-exclamation-triangle fa-lg"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-danger" id="modalEliminarCategoriaLabel">Eliminar Categoría</h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3" style="background-color: #fdf2f2;">
                <p class="text-dark mb-3" style="font-size: 0.95rem;">
                    ¿Está seguro de que desea eliminar la categoría <strong id="modal-categoria-nombre" class="text-danger"></strong>?
                </p>
                <div class="alert border border-danger bg-white text-danger rounded p-3 mb-4 small">
                    <i class="fa fa-exclamation-triangle mr-1"></i>
                    Atención: Esta categoría contiene <strong id="modal-categoria-tests" class="pl-1 pr-2">0 tests</strong> y <strong id="modal-categoria-preguntas" class="text-danger pl-1">0 preguntas</strong>. Todos los elementos asociados serán eliminados definitivamente.
                </div>
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-light border rounded-pill px-4 mr-2 text-dark font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <a href="#" id="btn-confirmar-eliminar-categoria" class="btn btn-danger rounded-pill px-4 text-white font-weight-bold" style="background-color: #e53e3e; border-color: #e53e3e;">Eliminar</a>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal para Eliminar Test -->
<div class="modal fade" id="modalEliminarTest" tabindex="-1" role="dialog" aria-labelledby="modalEliminarTestLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4" style="background-color: #fdf2f2;">
                <div class="d-flex align-items-center">
                    <div class="d-flex align-items-center justify-content-center rounded-circle p-2 mr-3 text-danger" style="width: 40px; height: 40px; background-color: #fde8e8;">
                        <i class="fa fa-trash fa-lg"></i>
                    </div>
                    <h5 class="modal-title font-weight-bold text-danger" id="modalEliminarTestLabel">Eliminar Test</h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3" style="background-color: #fdf2f2;">
                <p class="text-dark mb-3" style="font-size: 0.95rem;">
                    ¿Está seguro de que desea eliminar el test <strong id="modal-test-nombre" class="text-danger"></strong>?
                </p>
                <p class="text-muted small mb-4">
                    El test se quitará de esta categoría y se moverá a la papelera del curso. Podrá restaurarlo más adelante si lo desea.
                </p>
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-light border rounded-pill px-4 mr-2 text-dark font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <a href="#" id="btn-confirmar-eliminar-test" class="btn btn-danger rounded-pill px-4 text-white font-weight-bold" style="background-color: #e53e3e; border-color: #e53e3e;">Eliminar</a>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal para Papelera del Curso -->
<div class="modal fade" id="modalPapeleraCurso" tabindex="-1" role="dialog" aria-labelledby="modalPapeleraCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg overflow-hidden">
            <div class="modal-header border-bottom px-4 pt-4 pb-3" style="background-color: #f4fbf7;">
                <div class="d-flex align-items-center w-100">
                    <div class="d-flex align-items-center justify-content-center rounded p-2 mr-3 text-success" style="width: 42px; height: 42px; background-color: #e3f5ec;">
                        <i class="fa fa-trash fa-lg"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center mb-1">
                            <span class="text-uppercase text-success font-weight-bold mr-2" style="font-size: 11px; letter-spacing: 0.5px;">Papelera del Curso</span>
                            <span class="badge badge-success px-2 py-1" style="font-size: 10px; background-color: #d1e7dd; color: #0f5132;" id="modal-badge-curso">DPP-2026</span>
                        </div>
                        <h5 class="modal-title font-weight-bold text-dark mb-0" id="modalPapeleraCursoLabel" style="font-size: 1.1rem;">Nombre del Curso</h5>
                    </div>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <small class="text-muted font-weight-bold" id="modal-trash-count">
                        <i class="fa fa-exclamation-circle text-warning mr-1"></i> 0 tests reciclados en esta papelera
                    </small>
                    <a href="#" id="btn-vaciar-papelera" class="text-danger font-weight-bold small text-decoration-none">
                        Vaciar Papelera
                    </a>
                </div>
                <div id="modal-trash-tests-container" style="max-height: 350px; overflow-y: auto; padding-right: 4px;"></div>
            </div>
            <div class="modal-footer border-top bg-light px-4 py-3">
                <button type="button" class="btn btn-light border rounded-pill px-4 text-dark font-weight-bold shadow-sm" data-dismiss="modal">Cerrar Papelera</button>
            </div>
        </div>
    </div>
</div>

<script>
    require(['jquery', 'core/modal_factory', 'core/str'], function($, ModalFactory, Str) {
        $(document).ready(function() {
            $(document).on('click', '.btn-abrir-importar', function(e) {
                var categoryid = $(this).attr('data-categoryid');
                $('#id_categoryid').val(categoryid);
            });

            $(document).on('click', '.btn-abrir-banco', function(e) {
                var categoryid = $(this).attr('data-categoryid');
                $('#id_bank_categoryid').val(categoryid);
            });

            $(document).on('click', '.btn-abrir-modal-curso', function(e) {
                e.preventDefault();
                var coursename = $(this).attr('data-coursename');
                var testcount = $(this).attr('data-testcount');
                var questioncount = $(this).attr('data-questioncount');
                var deleteurl = $(this).attr('data-deleteurl');

                $('#modal-curso-nombre').text('"' + coursename + '"');
                $('#modal-curso-tests').text(testcount + (testcount == 1 ? ' test' : ' tests'));
                $('#modal-curso-preguntas').text(questioncount + (questioncount == 1 ? ' pregunta' : ' preguntas'));
                $('#btn-confirmar-eliminar-curso').attr('href', deleteurl);

                $('#modalEliminarCurso').modal('show');
            });

            $(document).on('click', '.btn-abrir-modal-categoria', function(e) {
                e.preventDefault();
                var catname = $(this).attr('data-catname');
                var testcount = $(this).attr('data-testcount');
                var questioncount = $(this).attr('data-questioncount');
                var deleteurl = $(this).attr('data-deleteurl');

                $('#modal-categoria-nombre').text('"' + catname + '"');
                $('#modal-categoria-tests').text(testcount + (testcount == 1 ? ' test' : ' tests'));
                $('#modal-categoria-preguntas').text(questioncount + (questioncount == 1 ? ' pregunta' : ' preguntas'));
                $('#btn-confirmar-eliminar-categoria').attr('href', deleteurl);

                $('#modalEliminarCategoria').modal('show');
            });

            $(document).on('click', '.btn-abrir-papelera', function(e) {
                var coursename = $(this).attr('data-coursename');
                var emptyurl = $(this).attr('data-emptyurl');
                var testsRaw = $(this).attr('data-tests');
                var tests = testsRaw ? JSON.parse(testsRaw) : [];

                $('#modalPapeleraCursoLabel').text(coursename);

                var html = '';
                if (tests.length === 0) {
                    html = '<p class="text-muted text-center py-3">La papelera de este curso está vacía.</p>';
                } else {
                    html = '<div class="mb-3 text-right"><a href="' + emptyurl + '" class="btn btn-outline-danger btn-sm rounded-pill"><i class="fa fa-trash"></i> Vaciar papelera</a></div>';
                    html += '<ul class="list-group">';
                    $.each(tests, function(i, t) {
                        html += '<li class="list-group-item d-flex justify-content-between align-items-center">';
                        html += '<div><strong>' + t.name + '</strong><br><small class="text-muted">' + t.questions + ' preguntas - Eliminado: ' + t.date + '</small></div>';
                        html += '<a href="' + t.restoreurl + '" class="btn btn-success btn-sm rounded-pill text-white"><i class="fa fa-undo"></i> Restaurar</a>';
                        html += '</li>';
                    });
                    html += '</ul>';
                }
                $('#modal-trash-tests-container').html(html);
            });
            $(document).on('click', '.btn-abrir-modal-test', function(e) {
                e.preventDefault();
                var testname = $(this).attr('data-testname');
                var deleteurl = $(this).attr('data-deleteurl');

                $('#modal-test-nombre').text('"' + testname + '"');
                $('#btn-confirmar-eliminar-test').attr('href', deleteurl);

                $('#modalEliminarTest').modal('show');
            });
        });
    });
</script>
<?php echo $OUTPUT->footer(); ?>
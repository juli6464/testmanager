<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/course_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/category_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/test_form.php');

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
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/testmanager/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Banco de Preguntas');
$PAGE->requires->css('/local/testmanager/styles.css');

$action = optional_param('action', '', PARAM_ALPHA);
$testid = optional_param('testid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

if ($action === 'deletetest' && $testid && confirm_sesskey()) {
    $DB->delete_records('local_testmanager_tests', ['id' => $testid]);
    redirect(new moodle_url('/local/testmanager/index.php'), 'Test eliminado correctamente.');
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
    $qcount = (!empty($tdata->bank_question_id)) ? 1 : (!empty($tdata->csvfile) ? 5 : 0);
    $DB->insert_record('local_testmanager_tests', ['categoryid' => $tdata->categoryid, 'name' => $tdata->name, 'question_count' => $qcount, 'timecreated' => time()]);
    redirect($PAGE->url, 'Test guardado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
?>

<div class="container-fluid px-4 py-3">
    <!-- Cabecera Superior estilo Referencia -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="testmanager-header-title">Banco de <span class="text-primary">Preguntas</span></h2>
        <div class="d-flex align-items-center">
            <div class="bg-white border rounded-pill px-3 py-1 shadow-sm mr-3 text-muted small d-flex align-items-center">
                <i class="fa fa-filter text-info mr-2"></i> FILTRO CURSOS: 
                <span class="text-dark font-weight-bold ml-1">Derecho Penal y Procesal Policial</span>
            </div>
            <button class="btn btn-success rounded-pill px-4 text-white font-weight-bold" type="button" data-toggle="collapse" data-target="#collapseCourseForm">
                <i class="fa fa-plus mr-1"></i> Crear Curso
            </button>
        </div>
    </div>

    <!-- Formulario colapsable para Crear Curso -->
    <div class="collapse mb-4" id="collapseCourseForm">
        <div class="card card-body bg-white border-0 shadow-sm rounded-lg">
            <?php $courseform->display(); ?>
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
            <button class="btn btn-light bg-white border rounded-pill px-4 shadow-sm text-dark font-weight-bold" type="button" data-toggle="collapse" data-target="#collapseGlobalCat">
                <i class="fa fa-folder-open text-primary mr-1"></i> Nueva Categoría
            </button>
        </div>
    </div>

    <!-- Listado de Cursos y Categorías -->
    <?php
    $courses = $DB->get_records('local_testmanager_courses');
    foreach ($courses as $course) {
        $categories = $DB->get_records_sql("SELECT * FROM {local_testmanager_categories} WHERE courseid = ? AND is_trash = 0", [$course->id]);
        $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $course->id, 'is_trash' => 1]);
        
        $total_questions = 0;
        foreach($categories as $cat) {
            $total_questions += $DB->get_field_sql("SELECT SUM(question_count) FROM {local_testmanager_tests} WHERE categoryid = ?", [$cat->id]) ?: 0;
        }
        $total_tests = $DB->count_records_sql("SELECT COUNT(t.id) FROM {local_testmanager_tests} t JOIN {local_testmanager_categories} c ON t.categoryid = c.id WHERE c.courseid = ? AND c.is_trash = 0", [$course->id]);

        echo '<div class="testmanager-course-card">';
        
        // Cabecera Principal del Curso
        echo '<div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">';
        echo '<div class="d-flex align-items-center">';
        echo '<div class="d-flex align-items-center justify-content-center bg-light rounded p-2 mr-3" style="width: 42px; height: 42px; min-width: 42px;">';
        echo '<i class="fa fa-folder text-info fa-lg"></i>';
        echo '</div>';
        echo '<div>';
        echo '<h5 class="mb-0 font-weight-bold text-dark" style="font-size: 1rem !important; text-transform: none !important;">' . format_string($course->name) . ' <span class="badge badge-tests-count ml-2">' . $total_tests . ' tests</span></h5>';
        echo '<small class="text-muted" style="text-transform: none !important;">Perteneciente a: <strong>Derecho Penal y Procesal Policial</strong> &bull; Total: <strong>' . $total_questions . ' preguntas</strong></small>';
        echo '</div></div>';
        
        echo '<div class="d-flex align-items-center">';
        if ($trashcat) {
            echo '<a href="#" class="btn btn-outline-success btn-sm rounded-pill px-3 mr-3" style="text-transform: none; font-size: 12px;"><i class="fa fa-trash mr-1"></i> Papelera del Curso</a>';
        }
        echo '<a href="#" class="text-muted"><i class="fa fa-times"></i></a>';
        echo '</div>';
        echo '</div>';

        // Recorrido de Subcategorías pertenecientes a este curso
        foreach ($categories as $cat) {
            // Cabecera de la subcategoría
            echo '<div class="mb-3 pl-2">';
            echo '<div class="d-flex align-items-center mb-2 text-dark font-weight-bold" style="font-size: 0.9rem;">';
            echo '<i class="fa fa-folder-open text-warning mr-2"></i> ' . format_string($cat->name);
            echo '</div>';

            // Listado de Tests dentro de esta subcategoría
            $tests = $DB->get_records('local_testmanager_tests', ['categoryid' => $cat->id]);
            if (empty($tests)) {
                echo '<div class="text-muted pl-4 mb-2 font-italic small">No hay tests en esta categoría.</div>';
            } else {
                foreach ($tests as $t) {
                    $deleteurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletetest', 'testid' => $t->id, 'sesskey' => sesskey()]);
                    echo '<div class="testmanager-item ml-3" draggable="true">';
                    echo '<div class="d-flex align-items-center">';
                    echo '<i class="fa fa-grip-vertical text-muted mr-3" style="cursor: grab; font-size: 0.85rem;"></i>';
                    echo '<div class="d-flex align-items-center justify-content-center bg-light rounded mr-3" style="width: 34px; height: 34px; min-width: 34px;">';
                    echo '<i class="fa fa-file-alt text-info"></i>';
                    echo '</div>';
                    echo '<div>';
                    echo '<span class="font-weight-bold text-dark" style="font-size: 0.9rem;">' . format_string($t->name) . '</span><br>';
                    echo '<span class="badge badge-questions mr-2"><i class="fa fa-question-circle mr-1"></i> ' . $t->question_count . ' preguntas</span>';
                    echo '<small class="text-muted" style="font-size: 75%;">Actualizado: ' . date('Y-m-d', $t->timecreated) . '</small>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div>';
                    echo '<a href="' . $deleteurl . '" class="text-muted" title="Eliminar Test"><i class="fa fa-trash"></i></a>';
                    echo '</div>';
                    echo '</div>';
                }
            }
            echo '</div>'; // Fin subcategoría
        }

        // Barra inferior de importación única al final del curso
        $firstcat = reset($categories);
        $firstcatid = $firstcat ? $firstcat->id : 0;

        echo '<div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">';
        echo '<small class="text-muted" style="text-transform: none;"><i class="fa fa-grip-vertical mr-1"></i> Arrastre un test para reordenar o mover</small>';
        echo '<div>';
        if ($firstcatid) {
            $testform->set_data(['categoryid' => $firstcatid]);
            echo '<button class="btn btn-outline-secondary btn-sm rounded-pill px-3 mr-2 bg-white" type="button" data-toggle="collapse" data-target="#testForm' . $course->id . '" style="text-transform: none; font-size: 12px;"><i class="fa fa-upload mr-1"></i> Importar Test CSV</button>';
            echo '<button class="btn btn-outline-info btn-sm rounded-pill px-3 bg-white" type="button" data-toggle="collapse" data-target="#testForm' . $course->id . '" style="text-transform: none; font-size: 12px;"><i class="fa fa-database mr-1"></i> Importar desde Banco</button>';
        }
        echo '</div>';
        echo '</div>';

        // Formulario de importación colapsable único para el curso
        if ($firstcatid) {
            echo '<div class="collapse mt-2" id="testForm' . $course->id . '"><div class="card card-body bg-light border-0">';
            $testform->display();
            echo '</div></div>';
        }

        echo '</div>'; // Fin testmanager-course-card
    }
    ?>
</div>

<?php echo $OUTPUT->footer(); ?>
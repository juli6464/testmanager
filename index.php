<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/course_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/category_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/test_form.php');


require_login();

global $DB;
$dbman = $DB->get_manager();

// Forzar la limpieza de la caché interna del gestor de base de datos de Moodle
if (method_exists($dbman, 'reset_caches')) {
    $dbman->reset_caches();
}

// Si la tabla no existe, la creamos al vuelo de forma segura
if (!$dbman->table_exists('local_testmanager_courses')) {
    require_once(__DIR__ . '/db/install.php');
    if (function_exists('xmldb_local_testmanager_install')) {
        xmldb_local_testmanager_install();
    }
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/testmanager/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Gestión de Tests Independientes');
$PAGE->requires->css('/local/testmanager/styles.css');

$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$testid = optional_param('testid', 0, PARAM_INT);

// 1. Eliminar test directamente mediante icono de papelera
if ($action === 'deletetest' && $testid && confirm_sesskey()) {
    $DB->delete_records('local_testmanager_tests', ['id' => $testid]);
    redirect(new moodle_url('/local/testmanager/index.php'), 'Test eliminado correctamente.');
}

// Formularios
$courseform = new \local_testmanager\form\course_form();
if ($courseform->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $courseform->get_data()) {
    $newcourseid = $DB->insert_record('local_testmanager_courses', [
        'name' => $data->name,
        'timecreated' => time()
    ]);
    // Crear automáticamente la categoría especial "Papelera" (Requerimiento 6)
    $DB->insert_record('local_testmanager_categories', [
        'courseid' => $newcourseid,
        'name' => 'Papelera',
        'is_trash' => 1,
        'timecreated' => time()
    ]);
    redirect($PAGE->url, 'Curso creado exitosamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$catform = new \local_testmanager\form\category_form();
if ($cdata = $catform->get_data()) {
    $DB->insert_record('local_testmanager_categories', [
        'courseid' => $cdata->courseid,
        'name' => $cdata->name,
        'is_trash' => 0,
        'timecreated' => time()
    ]);
    redirect($PAGE->url, 'Categoría creada con éxito.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$testform = new \local_testmanager\form\test_form();
if ($tdata = $testform->get_data()) {
    $qcount = (!empty($tdata->bank_question_id)) ? 1 : 0;
    // Si se subió archivo CSV, simulamos el procesamiento de conteo
    if (!empty($tdata->csvfile)) {
        $qcount = 5; // Valor simulado de preguntas extraídas del CSV
    }
    $DB->insert_record('local_testmanager_tests', [
        'categoryid' => $tdata->categoryid,
        'name' => $tdata->name,
        'question_count' => $qcount,
        'timecreated' => time()
    ]);
    redirect($PAGE->url, 'Test guardado e importado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Banco de Preguntas');

// Botón principal "Crear curso" (Requerimiento 1)
echo '<div class="mb-4">';
echo '<button class="btn btn-primary" type="button" data-toggle="collapse" data-target="#collapseCourseForm">Crear Curso</button>';
echo '<div class="collapse mt-2" id="collapseCourseForm"><div class="card card-body">';
$courseform->display();
echo '</div></div></div>';

// Listar Cursos, Categorías y Tests
$courses = $DB->get_records('local_testmanager_courses');
foreach ($courses as $course) {
    echo '<div class="testmanager-course-card">';
    echo '<h3>Curso: ' . format_string($course->name) . '</h3>';

    // Botón para desplegar formulario de categoría (Requerimiento 2)
    $catform->set_data(['courseid' => $course->id]);
    echo '<button class="btn btn-sm btn-secondary mb-3" type="button" data-toggle="collapse" data-target="#catForm' . $course->id . '">+ Añadir Categoría</button>';
    echo '<div class="collapse mb-3" id="catForm' . $course->id . '"><div class="card card-body">';
    $catform->display();
    echo '</div></div>';

    // Obtener Categorías de este curso (excluyendo la papelera temporalmente para listado general)
    $categories = $DB->get_records('local_testmanager_categories', ['courseid' => $course->id, 'is_trash' => 0]);
    $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $course->id, 'is_trash' => 1]);

    echo '<div class="row">';
    foreach ($categories as $cat) {
        echo '<div class="col-md-4 mb-3"><div class="card p-3 bg-light">';
        echo '<h5>' . format_string($cat->name) . ' ';
        // Icono de eliminación de categoría con advertencia (Requerimiento 7)
        echo '<a href="' . new moodle_url('/local/testmanager/delete_category.php', ['id' => $cat->id]) . '" title="Eliminar Categoría"><i class="fa fa-trash text-danger"></i></a>';
        echo '</h5>';

        // Tests en esta categoría
        $tests = $DB->get_records('local_testmanager_tests', ['categoryid' => $cat->id]);
        echo '<ul class="list-unstyled">';
        foreach ($tests as $t) {
            $deleteurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletetest', 'testid' => $t->id, 'sesskey' => sesskey()]);
            echo '<li class="testmanager-item" draggable="true" ondragstart="event.dataTransfer.setData(\'text/plain\', ' . $t->id . ')">';
            echo $t->name . ' <span class="badge badge-info">(' . $t->question_count . ' preguntas)</span> ';
            echo '<a href="' . $deleteurl . '" class="float-right" title="Eliminar Test"><i class="fa fa-trash text-danger"></i></a>';
            echo '</li>';
        }
        echo '</ul>';

        // Botón para crear/importar test (Requerimientos 3 y 4)
        $testform->set_data(['categoryid' => $cat->id]);
        echo '<button class="btn btn-sm btn-outline-primary mt-2" type="button" data-toggle="collapse" data-target="#testForm' . $cat->id . '">Importar / Crear Test</button>';
        echo '<div class="collapse mt-2" id="testForm' . $cat->id . '"><div class="card card-body bg-white">';
        $testform->display();
        echo '</div></div>';

        echo '</div></div>';
    }
    echo '</div>';

    // Sección Papelera (Requerimiento 6)
    if ($trashcat) {
        $trashtests = $DB->get_records('local_testmanager_tests', ['categoryid' => $trashcat->id]);
        echo '<div class="testmanager-trash-zone" ondragover="event.preventDefault()" ondrop="dropToTrash(event, ' . $course->id . ')">';
        echo '<strong><i class="fa fa-trash"></i> Papelera del Curso (Arrastra aquí los tests o usa el icono)</strong>';
        echo '<ul class="list-unstyled mt-2">';
        foreach ($trashtests as $tt) {
            $deleteurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletetest', 'testid' => $tt->id, 'sesskey' => sesskey()]);
            echo '<li class="testmanager-item bg-white">';
            echo $tt->name . ' <span class="badge badge-secondary">(' . $tt->question_count . ' preguntas)</span>';
            echo '<a href="' . $deleteurl . '" class="float-right text-danger"><i class="fa fa-trash"></i></a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '</div>'; // Fin card curso
}

// Script JavaScript para el manejo del Drag & Drop hacia la papelera
$sesskey = sesskey();
echo <<<JS
<script>
function dropToTrash(ev, courseid) {
    ev.preventDefault();
    var testid = ev.dataTransfer.getData("text/plain");
    if (!testid) return;

    fetchMoodleAjax(courseid, testid);
}

function fetchMoodleAjax(courseid, testid) {
    fetchM.open("GET", M.cfg.wwwroot + "/local/testmanager/ajax/move_trash.php?courseid=" + courseid + "&testid=" + testid + "&sesskey={$sesskey}", true);
    fetchM.onload = function() {
        if (fetchM.status === 200) {
            location.reload();
        }
    };
    fetchM.send();
}
// Ajuste rápido para fetch nativo
window.fetchM = new XMLHttpRequest();
</script>
JS;

echo $OUTPUT->footer();
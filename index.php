<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/course_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/category_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/test_form.php');
require_once($CFG->dirroot . '/local/testmanager/classes/form/bank_test_form.php');

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
$courseid = optional_param('courseid', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT); // Corregido: Definido correctamente
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
    $DB->delete_records('local_testmanager_tests', ['id' => $testid]);
    redirect(new moodle_url('/local/testmanager/index.php'), 'Test eliminado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar el curso y sus categorías/tests asociados
if ($action === 'deletecourse' && $courseid && confirm_sesskey()) {
    $categories = $DB->get_records('local_testmanager_categories', ['courseid' => $courseid]);
    foreach ($categories as $cat) {
        $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
    }
    $DB->delete_records('local_testmanager_categories', ['courseid' => $courseid]);
    $DB->delete_records('local_testmanager_courses', ['id' => $courseid]);
    redirect(new moodle_url('/local/testmanager/index.php'), 'Curso eliminado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Lógica para eliminar una categoría y sus tests asociados
if ($action === 'deletecategory' && $categoryid && confirm_sesskey()) {
    $cat = $DB->get_record('local_testmanager_categories', ['id' => $categoryid, 'is_trash' => 0]);
    if ($cat) {
        $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
        $DB->delete_records('local_testmanager_categories', ['id' => $cat->id]);
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
    $categoryid = $tdata->categoryid;
    $testname = $tdata->name;
    
    $question_count = 0;
    
    // Procesar el archivo CSV subido a través del filepicker de Moodle
    $draftitemid = $tdata->csvfile;
    global $USER;
    $context = context_system::instance(); // O el contexto adecuado de tu curso/sistema
    
    $fs = get_file_storage();
    $files = $fs->get_area_files($USER->id, 'user', 'draft', $draftitemid, 'id DESC', false);
    
    $csvcontent = '';
    foreach ($files as $file) {
        if (!$file->is_directory()) {
            $csvcontent = $file->get_content();
            break;
        }
    }

    if (!empty($csvcontent)) {
        // Convertir el contenido del CSV en líneas
        $lines = explode(PHP_EOL, $csvcontent);
        // Omitir cabecera si la tiene y contar registros válidos
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $question_count++;
            }
        }
        // Si tu CSV tiene cabecera, resta 1: $question_count = max(0, $question_count - 1);
    }

    // Insertar el test con el conteo real de preguntas extraído del CSV
    $DB->insert_record('local_testmanager_tests', [
        'categoryid' => $categoryid,
        'name' => $testname,
        'question_count' => $question_count,
        'timecreated' => time()
    ]);

    redirect($PAGE->url, 'Test e importación de CSV procesados correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
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
    if ($filtercourse > 0) {
        $courses = $DB->get_records('local_testmanager_courses', ['id' => $filtercourse]);
    } else {
        $courses = $DB->get_records('local_testmanager_courses');
    }
    
    foreach ($courses as $course) {
        $categories = $DB->get_records_sql("SELECT * FROM {local_testmanager_categories} WHERE courseid = ? AND is_trash = 0", [$course->id]);
        $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $course->id, 'is_trash' => 1]);
        
        $total_questions = 0;
        foreach($categories as $cat) {
            $total_questions += $DB->get_field_sql("SELECT SUM(question_count) FROM {local_testmanager_tests} WHERE categoryid = ?", [$cat->id]) ?: 0;
        }
        $total_tests = $DB->count_records_sql("SELECT COUNT(t.id) FROM {local_testmanager_tests} t JOIN {local_testmanager_categories} c ON t.categoryid = c.id WHERE c.courseid = ? AND c.is_trash = 0", [$course->id]);

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
            // Calcular contadores por categoría correctamente
            $cat_tests_count = $DB->count_records('local_testmanager_tests', ['categoryid' => $cat->id]);
            $cat_questions_count = $DB->get_field_sql("SELECT SUM(question_count) FROM {local_testmanager_tests} WHERE categoryid = ?", [$cat->id]) ?: 0;

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
            
            echo '<a href="#" class="text-muted btn-abrir-modal-categoria" data-toggle="modal" data-target="#modalEliminarCategoria" data-catname="' . s($cat->name) . '" data-testcount="' . $cat_tests_count . '" data-questioncount="' . $cat_questions_count . '" data-deleteurl="' . $deletecaturl->out(false) . '" title="Eliminar Categoría"><i class="fa fa-trash" style="font-size: 0.85rem;"></i></a>';
            echo '</div>';

            $tests = $DB->get_records('local_testmanager_tests', ['categoryid' => $cat->id]);
            if (empty($tests)) {
                echo '<div class="text-muted pl-4 mb-2 font-italic small">No hay tests en esta categoría.</div>';
            } else {
                foreach ($tests as $t) {
                    $deleteurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletetest', 'testid' => $t->id, 'sesskey' => sesskey()]);
                    echo '<div class="testmanager-item ml-3 p-2 border rounded mb-2 bg-light d-flex justify-content-between align-items-center" draggable="true">';
                    echo '<div class="d-flex align-items-center">';
                    echo '<i class="fa fa-grip-vertical text-muted mr-3" style="cursor: grab; font-size: 0.85rem;"></i>';
                    echo '<div class="d-flex align-items-center justify-content-center bg-white rounded mr-3 shadow-sm" style="width: 34px; height: 34px; min-width: 34px;">';
                    echo '<i class="fa fa-file-alt text-info"></i>';
                    echo '</div>';
                    echo '<div>';
                    echo '<span class="font-weight-bold text-dark" style="font-size: 0.9rem;">' . format_string($t->name) . '</span><br>';
                    echo '<span class="badge badge-info mr-2"><i class="fa fa-question-circle mr-1"></i> ' . $t->question_count . ' preguntas</span>';
                    echo '<small class="text-muted" style="font-size: 75%;">Actualizado: ' . date('Y-m-d', $t->timecreated) . '</small>';
                    echo '</div>';
                    echo '</div>';
                    echo '<div>';
                    echo '<a href="' . $deleteurl->out(false) . '" class="text-muted" title="Eliminar Test"><i class="fa fa-trash"></i></a>';
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
                <?php
                $mform = new \local_testmanager\form\test_form();
                $mform->display();
                ?>
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
                    Atención: Todos los elementos asociados a esta categoría serán eliminados definitivamente.
                </div>
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-light border rounded-pill px-4 mr-2 text-dark font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <a href="#" id="btn-confirmar-eliminar-categoria" class="btn btn-danger rounded-pill px-4 text-white font-weight-bold" style="background-color: #e53e3e; border-color: #e53e3e;">Eliminar</a>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal para Papelera del Curso -->
<div class="modal fade" id="modalPapeleraCurso" tabindex="-1" role="dialog" aria-labelledby="modalPapeleraCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg overflow-hidden">
            
            <!-- Cabecera Verde Clara del Modal -->
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

            <!-- Cuerpo del Modal -->
            <div class="modal-body px-4 py-3 bg-white">
                <!-- Alerta de conteo y botón Vaciar Papelera -->
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <small class="text-muted font-weight-bold" id="modal-trash-count">
                        <i class="fa fa-exclamation-circle text-warning mr-1"></i> 0 tests reciclados en esta papelera
                    </small>
                    <a href="#" id="btn-vaciar-papelera" class="text-danger font-weight-bold small text-decoration-none">
                        Vaciar Papelera
                    </a>
                </div>

                <!-- Contenedor dinámico de la lista de tests eliminados -->
                <div id="modal-trash-tests-container" style="max-height: 350px; overflow-y: auto; padding-right: 4px;">
                    <!-- Los tests se inyectarán aquí mediante JS -->
                </div>
            </div>

            <!-- Pie del Modal -->
            <div class="modal-footer border-top bg-light px-4 py-3">
                <button type="button" class="btn btn-light border rounded-pill px-4 text-dark font-weight-bold shadow-sm" data-dismiss="modal">Cerrar Papelera</button>
            </div>

        </div>
    </div>
</div>

<!-- Scripts unificados de interactividad -->
<script>
require(['jquery'], function($) {
    $(document).ready(function() {
        // Pasar ID de categoría al abrir el modal de CSV
        $('.btn-abrir-importar').on('click', function() {
            var categoryid = $(this).data('categoryid');
            $('#modalImportarTest input[name="categoryid"]').val(categoryid);
        });
        // Pasar ID de categoría al abrir el modal de banco
        $('.btn-abrir-banco').on('click', function() {
            var categoryid = $(this).data('categoryid');
            $('#modalImportarBanco input[name="categoryid"]').val(categoryid);
        });

        // Inyectar datos al modal de eliminar curso
        $('.btn-abrir-modal-curso').on('click', function() {
            var coursename = $(this).data('coursename');
            var testcount = $(this).data('testcount');
            var questioncount = $(this).data('questioncount');
            var deleteurl = $(this).data('deleteurl');

            $('#modal-curso-nombre').text('"' + coursename + '"');
            $('#modal-curso-tests').text(testcount + (testcount == 1 ? ' test' : ' tests'));
            $('#modal-curso-preguntas').text(questioncount + (questioncount == 1 ? ' pregunta' : ' preguntas'));
            
            // Asigna formalmente la URL de eliminación al botón de confirmación del modal
            $('#btn-confirmar-eliminar-curso').attr('href', deleteurl);
        });

        // Inyectar datos al modal de papelera del curso
        $('.btn-abrir-papelera').on('click', function() {
            var coursename = $(this).data('coursename');
            var emptyurl = $(this).data('emptyurl');
            var tests = $(this).data('tests');

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
            $('#modal-papelera-contenido').html(html);
        });
    });
});
</script>

<?php echo $OUTPUT->footer(); ?>
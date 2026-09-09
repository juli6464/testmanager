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
$courseid = optional_param('courseid', 0, PARAM_INT); // NUEVO
$search = optional_param('search', '', PARAM_TEXT);
$filtercourse = optional_param('filtercourse', 0, PARAM_INT);

// Lógica para restaurar un test individual desde la papelera
if ($action === 'restoretest' && $testid && confirm_sesskey()) {
    // Buscamos el curso al que pertenece este curso a través de la categoría de papelera para moverlo a la primera categoría activa
    $test = $DB->get_record('local_testmanager_tests', ['id' => $testid]);
    if ($test) {
        $trashcat = $DB->get_record('local_testmanager_categories', ['id' => $test->categoryid, 'is_trash' => 1]);
        if ($trashcat) {
            // Encontrar la primera categoría no papelera de este curso
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
    redirect(new moodle_url('/local/testmanager/index.php'), 'Test eliminado correctamente.');
}

// NUEVO: Lógica para eliminar el curso y sus categorías/tests asociados
if ($action === 'deletecourse' && $courseid && confirm_sesskey()) {
    // Buscar categorías del curso
    $categories = $DB->get_records('local_testmanager_categories', ['courseid' => $courseid]);
    foreach ($categories as $cat) {
        $DB->delete_records('local_testmanager_tests', ['categoryid' => $cat->id]);
    }
    $DB->delete_records('local_testmanager_categories', ['courseid' => $courseid]);
    $DB->delete_records('local_testmanager_courses', ['id' => $courseid]);
    redirect(new moodle_url('/local/testmanager/index.php'), 'Curso eliminado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
}

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
        
        // echo '<div class="d-flex align-items-center">';
        // if ($trashcat) {
        //     echo '<a href="#" class="btn btn-outline-success btn-sm rounded-pill px-3 mr-3" style="text-transform: none; font-size: 12px;"><i class="fa fa-trash mr-1"></i> Papelera del Curso</a>';
        // }


        // // Botón X para eliminar curso...
        
        // // AQUÍ CAMBIAMOS EL ENLACE DE LA X PARA ABRIR EL MODAL DINÁMICAMENTE
        // $deletecourseurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletecourse', 'courseid' => $course->id, 'sesskey' => sesskey()]);
        // echo '<a href="#" class="text-muted btn-abrir-modal-curso" data-toggle="modal" data-target="#modalEliminarCurso" data-coursename="' . s($course->name) . '" data-testcount="' . $total_tests . '" data-questioncount="' . $total_questions . '" data-deleteurl="' . $deletecourseurl . '"><i class="fa fa-times"></i></a>';
        
        // echo '</div>';
        // echo '</div>';
        echo '<div class="d-flex align-items-center">';
        if ($trashcat) {
            // Consultar los tests que están dentro de la categoría de papelera de este curso
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

            // El botón se muestra SIEMPRE que exista la categoría de papelera del curso
            echo '<button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 mr-3 btn-abrir-papelera" style="text-transform: none; font-size: 12px;" ' .
                 'data-toggle="modal" data-target="#modalPapeleraCurso" ' .
                 'data-coursename="' . s($course->name) . '" ' .
                 'data-emptyurl="' . $emptytrashurl . '" ' .
                 'data-tests=\'' . json_encode($tests_data) . '\'>' .
                 '<i class="fa fa-trash mr-1"></i> Papelera del Curso</button>';
        }

        // Botón para eliminar curso (la X)
        $deletecourseurl = new moodle_url('/local/testmanager/index.php', ['action' => 'deletecourse', 'courseid' => $course->id, 'sesskey' => sesskey()]);
        echo '<a href="#" class="text-muted btn-abrir-modal-curso" data-toggle="modal" data-target="#modalEliminarCurso" data-coursename="' . s($course->name) . '" data-testcount="' . $total_tests . '" data-questioncount="' . $total_questions . '" data-deleteurl="' . $deletecourseurl . '"><i class="fa fa-times"></i></a>';
        
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
            // Botón para Importar Test CSV (Apunta al modal #modalImportarTest)
            echo '<button class="btn btn-outline-secondary btn-sm rounded-pill px-3 mr-2 bg-white btn-abrir-importar" type="button" data-toggle="modal" data-target="#modalImportarTest" data-categoryid="' . $firstcatid . '" style="text-transform: none; font-size: 12px;"><i class="fa fa-upload mr-1"></i> Importar Test CSV</button>';

            // Botón para Importar desde Banco (Apunta al modal #modalImportarBanco)
            echo '<button class="btn btn-outline-info btn-sm rounded-pill px-3 bg-white btn-abrir-banco" type="button" data-toggle="modal" data-target="#modalImportarBanco" data-categoryid="' . $firstcatid . '" style="text-transform: none; font-size: 12px;"><i class="fa fa-database mr-1"></i> Importar desde Banco</button>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // Fin testmanager-course-card
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
                <?php 
                // Renderizar el formulario dentro del modal
                $catform->display(); 
                ?>
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
                <?php 
                $courseform->display(); 
                ?>
            </div>
        </div>
    </div>
</div>
<!-- Modal para Importar Test CSV -->
<div class="modal fade" id="modalImportarTest" tabindex="-1" role="dialog" aria-labelledby="modalImportarTestLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            
            <!-- Cabecera del Modal -->
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

            <!-- Cuerpo del Modal (Donde se renderiza tu Moodleform de CSV) -->
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
            
            <!-- Cabecera del Modal -->
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

            <!-- Cuerpo del Modal -->
            <div class="modal-body px-4 py-3">
                <?php
                $bankform = new \local_testmanager\form\bank_test_form();
                $bankform->display();
                ?>
            </div>

        </div>
    </div>
</div>

<!-- Scripts de interactividad para los modales -->
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

        // Actualizar contador dinámico de tests seleccionados en el banco
        function updateCounter() {
            var count = $('.test-checkbox:checked').length;
            $('#selected-counter').text(count + ' tests seleccionados');
        }

        $(document).on('change', '.test-checkbox', function() {
            updateCounter();
        });

        // Botón "Seleccionar todos" del banco
        $('#select-all-tests').on('click', function(e) {
            e.preventDefault();
            var allChecked = $('.test-checkbox:checked').length === $('.test-checkbox').length;
            $('.test-checkbox').prop('checked', !allChecked).trigger('change');
        });
    });
});
</script>
<?php echo $OUTPUT->footer(); ?>
<!-- Modal para Importar desde Banco de Preguntas -->
<div class="modal fade" id="modalImportarBanco" tabindex="-1" role="dialog" aria-labelledby="modalImportarBancoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            
            <!-- Cabecera del Modal -->
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

            <!-- Cuerpo del Modal -->
            <div class="modal-body px-4 py-3">
                <?php
                $bankform = new \local_testmanager\form\bank_test_form();
                $bankform->display();
                ?>
            </div>

        </div>
    </div>
</div>

<!-- Script de interactividad para selección múltiple y contadores -->
<script>
require(['jquery'], function($) {
    $(document).ready(function() {
        // Pasar ID de categoría al abrir el modal de banco
        $('.btn-abrir-banco').on('click', function() {
            var categoryid = $(this).data('categoryid');
            $('#modalImportarBanco input[name="categoryid"]').val(categoryid);
        });

        // Actualizar contador dinámico de tests seleccionados
        function updateCounter() {
            var count = $('.test-checkbox:checked').length;
            $('#selected-counter').text(count + ' tests seleccionados');
        }

        $(document).on('change', '.test-checkbox', function() {
            updateCounter();
        });

        // Botón "Seleccionar todos"
        $('#select-all-tests').on('click', function(e) {
            e.preventDefault();
            var allChecked = $('.test-checkbox:checked').length === $('.test-checkbox').length;
            $('.test-checkbox').prop('checked', !allChecked).trigger('change');
        });
    });
});
</script>

<!-- Modal para Eliminar Curso -->
<div class="modal fade" id="modalEliminarCurso" tabindex="-1" role="dialog" aria-labelledby="modalEliminarCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            
            <!-- Cabecera del Modal (Estilo Alerta Rojo Suave) -->
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4" style="background-color: #fdf2f2; border-top-left-radius: 0.5rem; border-top-right-radius: 0.5rem;">
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

            <!-- Cuerpo del Modal -->
            <div class="modal-body px-4 py-3" style="background-color: #fdf2f2; border-bottom-left-radius: 0.5rem; border-bottom-right-radius: 0.5rem;">
                <p class="text-dark mb-3" style="font-size: 0.95rem;">
                    ¿Está seguro de que desea eliminar el curso <strong id="modal-curso-nombre" class="text-danger"></strong>?
                </p>

                <!-- Caja de Advertencia Interna -->
                <div class="alert border border-danger bg-white text-danger rounded p-3 mb-4 small" style="background-color: #fff5f5 !important;">
                    <i class="fa fa-exclamation-triangle mr-1"></i> 
                    Atención: Este curso contiene <strong id="modal-curso-tests" class="pl-1 pr-2">0 tests</strong> y <strong id="modal-curso-preguntas" class="text-danger pl-1">0 preguntas</strong>. Todos los elementos asociados serán removidos definitivamente del sistema.
                </div>

                <!-- Botones de Acción -->
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-light border rounded-pill px-4 mr-2 text-dark font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <a href="#" id="btn-confirmar-eliminar-curso" class="btn btn-danger rounded-pill px-4 text-white font-weight-bold" style="background-color: #e53e3e; border-color: #e53e3e;">Eliminar</a>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Script para inyectar datos dinámicos al modal de eliminación de curso -->
<script>
require(['jquery'], function($) {
    $(document).ready(function() {
        $('.btn-abrir-modal-curso').on('click', function() {
            var coursename = $(this).data('coursename');
            var testcount = $(this).data('testcount');
            var questioncount = $(this).data('questioncount');
            var deleteurl = $(this).data('deleteurl');

            $('#modal-curso-nombre').text('"' + coursename + '"');
            $('#modal-curso-tests').text(testcount + (testcount == 1 ? ' test' : ' tests'));
            $('#modal-curso-preguntas').text(questioncount + (questioncount == 1 ? ' pregunta' : ' preguntas'));
            $('#btn-confirmar-eliminar-curso').attr('href', deleteurl);
        });
    });
});
</script>
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

<!-- Script para poblar dinámicamente el modal de Papelera -->
<script>
require(['jquery'], function($) {
    $(document).ready(function() {
        $('.btn-abrir-papelera').on('click', function() {
            var coursename = $(this).data('coursename');
            var emptyurl = $(this).data('emptyurl');
            var tests = $(this).data('tests');

            $('#modalPapeleraCursoLabel').text(coursename);
            $('#modal-badge-curso').text(coursename);
            $('#btn-vaciar-papelera').attr('href', emptyurl);
            
            var count = tests.length;
            $('#modal-trash-count').html('<i class="fa fa-exclamation-circle text-warning mr-1"></i> ' + count + (count === 1 ? ' test reciclado en esta papelera' : ' tests reciclados en esta papelera'));

            var container = $('#modal-trash-tests-container');
            container.empty();

            if (count === 0) {
                container.html('<div class="text-center text-muted py-4 font-italic">No hay tests en la papelera.</div>');
            } else {
                tests.forEach(function(t) {
                    var cardHtml = `
                    <div class="card border rounded p-3 mb-3 shadow-sm bg-white" style="border-color: #e2e8f0 !important;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 0.95rem;">${t.name}</h6>
                                <div>
                                    <span class="badge badge-light border text-success mr-2 px-2 py-1 font-weight-bold" style="font-size: 11px; background-color: #f0fdf4 !important;"><i class="fa fa-question-circle mr-1"></i> ${t.questions} preguntas</span>
                                    <small class="text-muted" style="font-size: 11px;">Eliminado: ${t.date}</small>
                                </div>
                            </div>
                            <a href="${t.restoreurl}" class="btn btn-success btn-sm rounded-pill px-3 text-white font-weight-bold shadow-sm" style="background-color: #51cf66; border-color: #51cf66; font-size: 12px;">
                                <i class="fa fa-undo mr-1"></i> Restaurar
                            </a>
                        </div>
                    </div>`;
                    container.append(cardHtml);
                });
            }
        });
    });
});
</script>
<?php echo $OUTPUT->footer(); ?>
<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class bank_test_form extends \moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        
        // 1. Capturar los filtros actuales de la petición
        $filtercourse   = optional_param('filter_course', 0, PARAM_INT);
        $filtercategory = optional_param('filter_category', 0, PARAM_INT);
        $banksearch     = optional_param('bank_search', '', PARAM_TEXT);

        // 2. Cargar dinámicamente los cursos
        $courses = $DB->get_records('local_testmanager_courses', null, 'name ASC', 'id, name');

        // 3. Cargar dinámicamente las categorías EXCLUYENDO LA PAPELERA
        // Asegúrate de cambiar 'is_trash' por el nombre real de tu columna si es diferente (ej: 'deleted', etc.)
        if ($filtercourse > 0) {
            $categories = $DB->get_records_select(
                'local_testmanager_categories', 
                'courseid = ? AND is_trash = 0', 
                [$filtercourse], 
                'name ASC', 
                'id, name'
            );
        } else {
            $categories = $DB->get_records_select(
                'local_testmanager_categories', 
                'is_trash = 0', 
                null, 
                'name ASC', 
                'id, name'
            );
        }

        // 4. Consultar dinámicamente los tests aplicando relaciones, filtros y omitiendo papelera
        $sql = "SELECT t.*, c.name as coursename, cat.name as catname 
                FROM {local_testmanager_tests} t
                JOIN {local_testmanager_categories} cat ON t.categoryid = cat.id
                JOIN {local_testmanager_courses} c ON cat.courseid = c.id
                WHERE cat.is_trash = 0";
        $params = [];

        if ($filtercourse > 0) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $filtercourse;
        }

        if ($filtercategory > 0) {
            $sql .= " AND cat.id = :catid";
            $params['catid'] = $filtercategory;
        }

        if (!empty($banksearch)) {
            $sql .= " AND (t.name LIKE :search)";
            $params['search'] = '%' . $DB->sql_like_escape($banksearch) . '%';
        }

        $sql .= " ORDER BY t.name ASC";
        $tests = $DB->get_records_sql($sql, $params);

        // Campo oculto para la categoría destino
        $mform->addElement('hidden', 'categoryid');
        $mform->setType('categoryid', PARAM_INT);

        // Contenedor HTML dinámico de la interfaz (Sin recargas automáticas molestas)
        $html = '
        <div class="container-fluid px-0">
            <!-- Barra de Búsqueda -->
            <div class="input-group bg-white rounded-pill border shadow-sm px-3 py-1 mb-3">
                <div class="input-group-prepend align-items-center border-0 bg-transparent">
                    <i class="fa fa-search text-muted"></i>
                </div>
                <input type="text" name="bank_search" value="' . s($banksearch) . '" class="form-control border-0 shadow-none" placeholder="Busca por nombre de test o palabra clave...">
            </div>

            <!-- Filtros de Curso y Categoría -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="small font-weight-bold text-muted"><i class="fa fa-book mr-1"></i> FILTRO CURSO</label>
                    <select name="filter_course" class="custom-select rounded-pill border shadow-sm px-3" style="font-size: 13px;">
                        <option value="0">Todos los Cursos</option>';
                        foreach ($courses as $c) {
                            $selected = ($filtercourse == $c->id) ? 'selected' : '';
                            $html .= '<option value="' . $c->id . '" ' . $selected . '>' . s($c->name) . '</option>';
                        }
        $html .= '  </select>
                </div>
                <div class="col-md-6">
                    <label class="small font-weight-bold text-muted"><i class="fa fa-folder mr-1"></i> FILTRO CATEGORÍA</label>
                    <select name="filter_category" class="custom-select rounded-pill border shadow-sm px-3" style="font-size: 13px;">
                        <option value="0">Todas las Categorías</option>';
                        foreach ($categories as $cat) {
                            $selected = ($filtercategory == $cat->id) ? 'selected' : '';
                            $html .= '<option value="' . $cat->id . '" ' . $selected . '>' . s($cat->name) . '</option>';
                        }
        $html .= '  </select>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <small class="text-muted font-weight-bold">Lista de Tests (' . count($tests) . ' encontrados)</small>
                <a href="#" class="text-info font-weight-bold small text-decoration-none" id="select-all-tests">Seleccionar todos</a>
            </div>

            <!-- Listado de Tarjetas de Tests con Checkboxes -->
            <div class="bank-tests-list" style="max-height: 280px; overflow-y: auto; padding-right: 4px;">';

        if (empty($tests)) {
            $html .= '
            <div class="text-center text-muted py-4 border rounded bg-light" style="font-size: 13px;">
                <i class="fa fa-info-circle mb-1"></i> No hay test creados con los filtros o criterios de búsqueda seleccionados.
            </div>';
        } else {
            foreach ($tests as $t) {
                $html .= '
                <div class="card border rounded p-2 mb-2 shadow-sm test-row-card" style="border-color: #bce8f1 !important; background-color: #f9fcfd;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="custom-control custom-checkbox d-flex align-items-center">
                            <input type="checkbox" class="custom-control-input test-checkbox" id="banktest_' . $t->id . '" name="selected_tests[]" value="' . $t->id . '">
                            <label class="custom-control-label text-dark font-weight-bold ml-2" for="banktest_' . $t->id . '" style="cursor: pointer; font-size: 13px;">
                                ' . s($t->name) . '
                                <br><small class="text-muted font-weight-normal" style="font-size: 11px;">Curso: ' . s($t->coursename ?? 'N/D') . ' / Categoría: ' . s($t->catname ?? 'N/D') . '</small>
                            </label>
                        </div>
                        <span class="badge badge-pill badge-light border text-info px-3 py-1 font-weight-bold" style="font-size: 11px;">' . ($t->question_count ?? 0) . ' preguntas</span>
                    </div>
                </div>';
            }
        }

        $html .= '
            </div>
        </div>';

        // Pie inferior del modal con los botones limpios
        $html .= '
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                <small class="text-muted font-weight-bold" id="selected-counter">0 tests seleccionados</small>
                <div>
                    <button type="button" class="btn btn-light text-dark px-4 mr-2" data-dismiss="modal" style="border-radius: 50rem; border: 1px solid #ced4da; font-size: 13px;">
                        Cancelar
                    </button>
                    <button type="submit" name="submitbutton" value="Importar" class="btn text-white font-weight-bold px-4" style="background-color: #00a2ed; border-radius: 50rem; border: none; font-size: 13px;">
                        Importar
                    </button>
                </div>
            </div>';

        // Inyectar el bloque completo en el formulario de Moodle
        $mform->addElement('html', $html);
    }
}
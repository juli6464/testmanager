<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class bank_test_form extends \moodleform {
    protected function definition() {
        $mform = $_form = $this->_form;
        
        // Campo oculto para la categoría donde se importarán los tests seleccionados
        $mform->addElement('hidden', 'categoryid');
        $mform->setType('categoryid', PARAM_INT);

        // Contenedor HTML con el diseño exacto de la interfaz de la imagen
        $html = '
        <div class="container-fluid px-0">
            <!-- Barra de Búsqueda -->
            <div class="input-group bg-white rounded-pill border shadow-sm px-3 py-1 mb-3">
                <div class="input-group-prepend align-items-center border-0 bg-transparent">
                    <i class="fa fa-search text-muted"></i>
                </div>
                <input type="text" name="bank_search" class="form-control border-0 shadow-none" placeholder="Busca por nombre de test o palabra clave...">
            </div>

            <!-- Filtros de Curso y Categoría -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="small font-weight-bold text-muted"><i class="fa fa-book mr-1"></i> FILTRO CURSO</label>
                    <select name="filter_course" class="custom-select rounded-pill border shadow-sm px-3" style="font-size: 13px;">
                        <option value="0">Todos los Cursos</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="small font-weight-bold text-muted"><i class="fa fa-folder mr-1"></i> FILTRO CATEGORÍA</label>
                    <select name="filter_category" class="custom-select rounded-pill border shadow-sm px-3" style="font-size: 13px;">
                        <option value="0">Todas las Categorías</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <small class="text-muted font-weight-bold">Lista de Tests (5 encontrados)</small>
                <a href="#" class="text-info font-weight-bold small text-decoration-none" id="select-all-tests">Seleccionar todos</a>
            </div>

            <!-- Listado de Tarjetas de Tests con Checkboxes -->
            <div class="bank-tests-list" style="max-height: 280px; overflow-y: auto; padding-right: 4px;">';

        // Simulación de los 5 tests de la imagen (puedes reemplazar esto luego con una consulta dinámica a tu base de datos)
        $mock_tests = [
            ['id' => 101, 'name' => 'Banco General: Normativa de Seguridad Vial y Control Tráfico', 'questions' => 45],
            ['id' => 102, 'name' => 'Banco Central: Custodia de Detenidos y Garantías Procedimentales', 'questions' => 32],
            ['id' => 103, 'name' => 'Evaluación Tipo Oposición: Protocolo de Primeros Auxilios Tac-Med', 'questions' => 50],
            ['id' => 104, 'name' => 'Test de Repaso: Ciberdelincuencia y Evidencia Digital', 'questions' => 28],
            ['id' => 105, 'name' => 'Batería de Preguntas: Delitos Ambientales y Protección Civil', 'questions' => 40],
        ];

        foreach ($mock_tests as $t) {
            $html .= '
            <div class="card border rounded p-2 mb-2 shadow-sm test-row-card" style="border-color: #bce8f1 !important; background-color: #f9fcfd;">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="custom-control custom-checkbox d-flex align-items-center">
                        <input type="checkbox" class="custom-control-input test-checkbox" id="banktest_' . $t['id'] . '" name="selected_tests[]" value="' . $t['id'] . '">
                        <label class="custom-control-label text-dark font-weight-bold ml-2" for="banktest_' . $t['id'] . '" style="cursor: pointer; font-size: 13px;">
                            ' . $t['name'] . '
                            <br><small class="text-muted font-weight-normal" style="font-size: 11px;">Pertenece al Banco Central de Preguntas</small>
                        </label>
                    </div>
                    <span class="badge badge-pill badge-light border text-info px-3 py-1 font-weight-bold" style="font-size: 11px;">' . $t['questions'] . ' preguntas</span>
                </div>
            </div>';
        }

        $html .= '
            </div>
            
            <!-- Pie inferior del modal (Contador y Paginación simulada) -->
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                <small class="text-muted font-weight-bold" id="selected-counter">0 tests seleccionados</small>
                <div class="btn-group border rounded shadow-sm bg-white" role="group">
                    <button type="button" class="btn btn-sm btn-light border-0 text-muted px-2"><i class="fa fa-chevron-left"></i></button>
                    <button type="button" class="btn btn-sm btn-light border-0 text-muted px-2"><i class="fa fa-chevron-right"></i></button>
                    <button type="button" class="btn btn-sm btn-light border-0 text-muted px-2"><i class="fa fa-undo"></i></button>
                </div>
            </div>
        </div>';

        $mform->addElement('html', $html);

        // Botones de acción estándar de Moodle (Cancelar / Importar) personalizados
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', 'Importar', ['class' => 'btn btn-info rounded-pill px-4 font-weight-bold text-white', 'style' => 'background-color: #00a2ed; border-color: #00a2ed;']);
        $buttonarray[] = $mform->createElement('cancel', 'cancel', 'Cancelar', ['class' => 'btn btn-light rounded-pill px-4 text-dark border', 'data-dismiss' => 'modal']);
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
    }
}
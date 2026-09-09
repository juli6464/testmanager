<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class test_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        
        // $mform->addElement('hidden', 'categoryid');
        global $DB;

        // Obtener todas las categorías activas agrupadas con su curso correspondiente
        $categories = $DB->get_records_sql("
            SELECT c.id as categoryid, CONCAT(co.name, ' / ', c.name) as catname 
            FROM {local_testmanager_categories} c
            JOIN {local_testmanager_courses} co ON c.courseid = co.id
            WHERE c.is_trash = 0
            ORDER BY co.name ASC, c.name ASC
        ");

        $catoptions = [];
        foreach ($categories as $cat) {
            $catoptions[$cat->categoryid] = $cat->catname;
        }

        // Selector explícito para elegir la categoría de destino al importar
        $mform->addElement('select', 'categoryid', 'Categoría de destino', $catoptions, [
            'class' => 'form-control rounded-pill px-3 py-2'
        ]);
        $mform->setType('categoryid', PARAM_INT);
        $mform->addRule('categoryid', 'Debe seleccionar una categoría', 'required', null, 'client');
        
        $mform->setType('categoryid', PARAM_INT);

        // Nombre del Test con estilo moderno
        $mform->addElement('text', 'name', 'Nombre del Test', [
            'placeholder' => 'Ej. Test Parcial - Módulo Especial',
            'class' => 'form-control rounded-pill px-3 py-2'
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', 'El nombre es obligatorio', 'required', null, 'client');

        // Contenedor visual superior para la zona de carga (Dropzone styling)
        $dropzone_header = '<div class="upload-dropzone-wrapper mt-3 mb-1">
            <label class="font-weight-bold text-dark mb-2">Upload CSV <span class="text-danger">*</span></label>
            <div class="border rounded-lg p-4 text-center bg-light position-relative" style="border: 2px dashed #cbd5e1 !important; background-color: #fafafa !important; border-radius: 12px;">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2" style="width: 48px; height: 48px; background-color: #e6f6f8; color: #17a2b8;">
                    <i class="fa fa-upload fa-lg"></i>
                </div>
                <div class="font-weight-bold text-info mb-1" style="cursor: pointer;">
                    Haga clic para seleccionar archivo CSV <span class="text-muted font-weight-normal">o arrastre el archivo aquí</span>
                </div>
                <small class="text-muted d-block">Soporta formato UTF-8 estandarizado de Moodle / Mascop CSV</small>';
        $mform->addElement('html', $dropzone_header);

        // Zona de carga de CSV (Filepicker nativo de Moodle integrado visualmente)
        $filepicker_options = ['accepted_types' => ['.csv'], 'maxbytes' => 0];
        $mform->addElement('filepicker', 'csvfile', '', null, $filepicker_options);
        $mform->addRule('csvfile', 'Debe seleccionar un archivo CSV', 'required', null, 'client');

        // Cierre del contenedor visual y enlace de descarga de ejemplo
        $dropzone_footer = '</div>
            <div class="d-flex justify-content-end mt-2">
                <a href="#" class="text-info font-weight-bold small text-decoration-none">Cargar archivo CSV de ejemplo</a>
            </div>
        </div>';
        $mform->addElement('html', $dropzone_footer);

        // Botones de acción estándar estilizados para el modal
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('cancel', 'cancel', 'Cancelar', ['class' => 'btn btn-light border rounded-pill px-4 text-dark font-weight-bold']);
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', 'Importar', ['class' => 'btn btn-info rounded-pill px-4 text-white font-weight-bold', 'style' => 'background-color: #5bc0de; border-color: #5bc0de;']);
        
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }
}
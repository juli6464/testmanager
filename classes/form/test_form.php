<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class test_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        
        $mform->addElement('hidden', 'categoryid');
        $mform->setType('categoryid', PARAM_INT);

        // Nombre del Test con placeholder y estilo moderno
        $mform->addElement('text', 'name', 'Nombre del Test', [
            'placeholder' => 'Ej. Test Parcial - Módulo Especial',
            'class' => 'form-control rounded-pill px-3'
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', 'El nombre es obligatorio', 'required', null, 'client');

        // Zona de carga de CSV optimizada con diseño visual tipo "Dropzone"
        $filepicker_options = ['accepted_types' => ['.csv']];
        $mform->addElement('filepicker', 'csvfile', 'Upload CSV', null, $filepicker_options);
        $mform->addRule('csvfile', 'Debe seleccionar un archivo CSV', 'required', null, 'client');

        // Texto de ayuda y enlace de descarga de ejemplo debajo del filepicker
        $helptext = '<div class="d-flex flex-column mt-2 text-right">
            <a href="#" class="text-info font-weight-bold small">Cargar archivo CSV de ejemplo</a>
        </div>';
        $mform->addElement('html', $helptext);

        // Botones de acción estándar (Cancelar y Importar)
        $this->add_action_buttons(true, 'Importar');
    }
}
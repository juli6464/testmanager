<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class course_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;

        // Campo de texto nativo limpio
        $mform->addElement('text', 'name', 'Nombre del curso');
        $mform->addRule('name', 'El nombre es obligatorio', 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        // Caja de aviso informativa institucional
        $infobox = '<div class="alert alert-success bg-light border border-success rounded-lg p-3 text-dark small d-flex align-items-start mt-3 mb-4" style="background-color: #f4fbf7 !important; border-color: #c6e7d1 !important;">
            <i class="fa fa-info-circle text-success mr-2 mt-1 fa-lg"></i>
            <div>Al crear el curso, este nacerá conteniendo automáticamente la categoría especial <strong class="pl-1 pr-1"> Papelera</strong> en el topo para la gestión de tests reciclados.</div>
        </div>';
        $mform->addElement('html', $infobox);

        // Botones de acción estándar de Moodle
        $this->add_action_buttons(true, 'Crear');

        $buttonar = $mform->getElement('buttonar');
        if ($buttonar) {
            $buttonar->updateAttributes(['class' => 'testmanager-actionbuttons-group course-form-buttons']);
        }
    }
}
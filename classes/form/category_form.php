<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class category_form extends \moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;

        // Obtener la lista de cursos para la selección del padre
        $courses = $DB->get_records_sql_menu("SELECT id, name FROM {local_testmanager_courses}");
        $courseoptions = ['' => 'Seleccione un curso padre...'] + ($courses ? $courses : []);

        // Campo de selección de Curso Padre
        $mform->addElement('select', 'courseid', 'Curso', $courseoptions);
        $mform->addRule('courseid', 'Debe seleccionar un curso', 'required', null, 'client');
        $mform->setType('courseid', PARAM_INT);

        // Campo de texto nativo limpio
        $mform->addElement('text', 'name', 'Nombre');
        $mform->addRule('name', 'El nombre es obligatorio', 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        // Botones de acción estándar de Moodle
        $this->add_action_buttons(true, 'Guardar');

        $buttonar = $mform->getElement('buttonar');
        if ($buttonar) {
            $buttonar->updateAttributes(['class' => 'testmanager-actionbuttons-group category-form-buttons']);
        }
    }
}
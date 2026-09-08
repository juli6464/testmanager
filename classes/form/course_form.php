<?php
namespace local_testmanager\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class course_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('text', 'name', 'Nombre del Curso', ['size' => '50']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', 'El nombre es obligatorio', 'required', null, 'client');
        $this->add_action_buttons(true, 'Crear Curso');
    }
}
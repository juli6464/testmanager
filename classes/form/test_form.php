<?php
namespace local_testmanager\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class test_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        
        $mform->addElement('hidden', 'categoryid');
        $mform->setType('categoryid', PARAM_INT);

        $mform->addElement('text', 'name', 'Nombre del Test', ['size' => '50']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', 'Obligatorio', 'required', null, 'client');

        // Opción 3: Importar mediante archivo CSV
        $mform->addElement('filepicker', 'csvfile', 'Importar preguntas (CSV)', null, ['accepted_types' => ['.csv']]);

        // Opción 4: Selección simulada de banco de preguntas nativo
        $options = [0 => 'Ninguna / Crear vacío'];
        global $DB;
        if ($questions = $DB->get_records_select('question', 'qtype != ?', ['description'], '', 'id, name', 0, 100)) {
            foreach ($questions as $q) {
                $options[$q->id] = $q->name;
            }
        }
        $mform->addElement('select', 'bank_question_id', 'Importar desde Banco de Preguntas', $options);

        $this->add_action_buttons(true, 'Guardar Test');
    }
}
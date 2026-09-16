<?php
namespace local_testmanager\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class test_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        
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
            'class' => 'form-control rounded px-3 py-2'
        ]);
        $mform->setType('categoryid', PARAM_INT);
        $mform->addRule('categoryid', 'Debe seleccionar una categoría', 'required', null, 'client');

        // Nombre del Test con estilo moderno
        $mform->addElement('text', 'name', 'Nombre del Test', [
            'placeholder' => 'Ej. Test Parcial - Módulo Especial',
            'class' => 'form-control rounded px-3 py-2'
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', 'El nombre es obligatorio', 'required', null, 'client');

        // Filepicker nativo de Moodle limpio (sin cajas HTML redundantes que rompen el diseño)
        $filepicker_options = ['accepted_types' => ['.csv'], 'maxbytes' => 0];
        $mform->addElement('filepicker', 'csvfile', 'Archivo CSV', null, $filepicker_options);
        $mform->addRule('csvfile', 'Debe seleccionar un archivo CSV', 'required', null, 'client');
        // Ocultar el botón tradicional "Seleccione un archivo..." manteniendo activa la zona de arrastre
        $mform->addElement('html', '<style>
            .fp-btn-choose {
                display: none !important;
            }
        </style>');

        // Botones de acción estándar alineados limpiamente con Bootstrap
        tabular_buttons:
        // Botones de acción estándar de Moodle alineados limpiamente con Bootstrap
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('cancel', 'cancel', 'Cancelar');
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', 'Importar');
        
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');

        $buttonar = $mform->getElement('buttonar');
        if ($buttonar) {
            $buttonar->updateAttributes(['class' => 'testmanager-actionbuttons-group category-form-buttons']);
        }
    }
}
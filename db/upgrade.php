<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for local_testmanager.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 */
function xmldb_local_testmanager_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026091500) {
        // Agregar sortorder a local_testmanager_categories
        $table = new xmldb_table('local_testmanager_categories');
        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Agregar sortorder a local_testmanager_tests
        $table2 = new xmldb_table('local_testmanager_tests');
        $field2 = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table2, $field2)) {
            $dbman->add_field($table2, $field2);
        }

        // Asegurar que moodlecourseid existe en courses
        $table3 = new xmldb_table('local_testmanager_courses');
        $field3 = new xmldb_field('moodlecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        if (!$dbman->field_exists($table3, $field3)) {
            $dbman->add_field($table3, $field3);
        }

        // Asegurar que quizid existe en tests
        $field4 = new xmldb_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        if (!$dbman->field_exists($table2, $field4)) {
            $dbman->add_field($table2, $field4);
        }

        upgrade_plugin_savepoint(true, 2026091500, 'local', 'testmanager');
    }

    if ($oldversion < 2026091501) {
        // The access.php capabilities file was added in this version.
        // No schema changes needed; the capability is installed by Moodle automatically.
        upgrade_plugin_savepoint(true, 2026091501, 'local', 'testmanager');
    }

    if ($oldversion < 2026091502) {
        // Guardar la categoría de origen para poder restaurar un test al sitio del que salió.
        $table = new xmldb_table('local_testmanager_tests');
        $field = new xmldb_field('origcategoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'categoryid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Asegurar que todo curso lógico tiene su categoría "Papelera".
        $courses = $DB->get_records('local_testmanager_courses', null, '', 'id');
        foreach ($courses as $course) {
            if (!$DB->record_exists('local_testmanager_categories', ['courseid' => $course->id, 'is_trash' => 1])) {
                $DB->insert_record('local_testmanager_categories', [
                    'courseid'    => $course->id,
                    'name'        => 'Papelera',
                    'is_trash'    => 1,
                    'timecreated' => time(),
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026091502, 'local', 'testmanager');
    }

    if ($oldversion < 2026091900) {
        // masterid: id del test "maestro" del que este es copia (permite saber qué otros
        // cuestionarios deben sincronizarse cuando cambian las preguntas del maestro).
        // timemodified: última vez que cambió el número de preguntas (para no mostrar
        // siempre la fecha de creación como si fuera la de "actualizado").
        $table = new xmldb_table('local_testmanager_tests');

        $fieldmaster = new xmldb_field('masterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $fieldmaster)) {
            $dbman->add_field($table, $fieldmaster);
        }

        $fieldmodified = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $fieldmodified)) {
            $dbman->add_field($table, $fieldmodified);
        }

        // Tabla de vínculos test maestro -> cuestionarios donde se importaron sus preguntas.
        $linktable = new xmldb_table('local_testmanager_test_links');
        $linktable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $linktable->add_field('masterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $linktable->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $linktable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $linktable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $linktable->add_index('masterid', XMLDB_INDEX_NOTUNIQUE, ['masterid']);
        $linktable->add_index('masterid-cmid', XMLDB_INDEX_UNIQUE, ['masterid', 'cmid']);

        if (!$dbman->table_exists($linktable)) {
            $dbman->create_table($linktable);
        }

        // Backfill: todo test existente antes de esta versión se trata como su propio
        // maestro (no se puede reconstruir el historial de copias hecho antes de esto).
        $DB->execute("UPDATE {local_testmanager_tests} SET masterid = id WHERE masterid = 0");

        upgrade_plugin_savepoint(true, 2026091900, 'local', 'testmanager');
    }

    return true;
}

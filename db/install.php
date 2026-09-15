<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_testmanager_install() {
    global $DB;
    $dbman = $DB->get_manager();
    $prefix = $DB->get_prefix();

    // 1. Tabla de Cursos
    $table1 = new xmldb_table('local_testmanager_courses');
    $table1->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table1->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table1->add_field('moodlecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
    $table1->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table1->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

    if (!$dbman->table_exists($table1)) {
        $dbman->create_table($table1);
    }

    // 2. Tabla de Categorías
    $table2 = new xmldb_table('local_testmanager_categories');
    $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table2->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table2->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table2->add_field('is_trash', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
    $table2->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table2->add_key('fk_course', XMLDB_KEY_FOREIGN, ['courseid'], 'local_testmanager_courses', ['id']);

    if (!$dbman->table_exists($table2)) {
        $dbman->create_table($table2);
    }

    // 3. Tabla de Tests
    $table3 = new xmldb_table('local_testmanager_tests');
    $table3->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table3->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table3->add_field('origcategoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table3->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
    $table3->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table3->add_field('question_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table3->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table3->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table3->add_key('fk_category', XMLDB_KEY_FOREIGN, ['categoryid'], 'local_testmanager_categories', ['id']);

    if (!$dbman->table_exists($table3)) {
        $dbman->create_table($table3);
    }

    return true;
}
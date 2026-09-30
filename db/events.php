<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Observadores de eventos de local_testmanager.
 *
 * Al añadir o quitar una pregunta del cuestionario de un test maestro se sincronizan en el
 * acto todos los cuestionarios donde se importó (ver local_testmanager_sync_master_test()),
 * sin esperar a que alguien abra local/testmanager/index.php.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 */

$observers = [
    [
        'eventname' => '\mod_quiz\event\slot_created',
        'callback'  => '\local_testmanager\observer::quiz_slots_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\slot_deleted',
        'callback'  => '\local_testmanager\observer::quiz_slots_changed',
    ],
];

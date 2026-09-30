<?php
namespace local_testmanager;

defined('MOODLE_INTERNAL') || die();

/**
 * Observadores de eventos de local_testmanager.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 */
class observer {

    /** @var bool evita que la propia sincronización (que también crea/borra slots) se reentre. */
    protected static $syncing = false;

    /**
     * Si el cuestionario modificado es el de un test maestro con copias, propaga el cambio
     * de preguntas a todas ellas en el momento.
     *
     * @param \core\event\base $event slot_created o slot_deleted de mod_quiz.
     * @return void
     */
    public static function quiz_slots_changed(\core\event\base $event) {
        global $DB, $CFG;

        if (self::$syncing || empty($event->other['quizid'])) {
            return;
        }

        $mastertest = $DB->get_record_sql("
            SELECT t.*
              FROM {local_testmanager_tests} t
              JOIN {local_testmanager_categories} c ON c.id = t.categoryid
             WHERE t.quizid = ?
               AND t.masterid = t.id
               AND c.is_trash = 0
               AND EXISTS (SELECT 1 FROM {local_testmanager_test_links} l WHERE l.masterid = t.id)",
            [(int) $event->other['quizid']], IGNORE_MULTIPLE);
        if (!$mastertest) {
            return;
        }

        require_once($CFG->dirroot . '/local/testmanager/lib.php');

        self::$syncing = true;
        try {
            local_testmanager_sync_master_test($mastertest);
        } finally {
            self::$syncing = false;
        }
    }
}

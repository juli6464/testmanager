<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Funciones auxiliares de local_testmanager.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 */

/**
 * Repara los cuestionarios de Test Manager cuyo course_module quedó apuntando a una fila
 * de {course_sections} que ya no existe (por ejemplo, si el curso perdió sus secciones).
 * Moodle no puede resolver ese cmid ("ID de módulo de curso no válido: X") aunque el
 * course_module siga en la tabla. Se reinsertan en la sección 0 de su curso con la misma
 * API que ya usa el plugin al crear cuestionarios (course_add_cm_to_section()).
 *
 * A propósito solo toca los cmid que son quizid de algún local_testmanager_tests, en
 * CUALQUIER curso (incluida la portada del sitio, donde viven los tests que todavía no se
 * han importado a un curso real) — nunca otros course_modules ajenos al plugin.
 *
 * @return void
 */
function local_testmanager_repair_test_cms() {
    global $DB, $CFG;

    $quizids = array_unique(array_filter(
        array_map('intval', $DB->get_fieldset_select('local_testmanager_tests', 'quizid', 'quizid > 0'))
    ));
    if (empty($quizids)) {
        return;
    }

    list($insql, $params) = $DB->get_in_or_equal($quizids);
    $cms = $DB->get_records_sql("
        SELECT cm.id, cm.course, cm.section
          FROM {course_modules} cm
          JOIN {modules} m ON m.id = cm.module
         WHERE m.name = 'quiz' AND cm.instance $insql", $params);
    if (empty($cms)) {
        return;
    }

    $validsectionsbycourse = [];
    $brokenbycourse = [];
    foreach ($cms as $cm) {
        $courseid = (int) $cm->course;
        if (!isset($validsectionsbycourse[$courseid])) {
            $validsectionsbycourse[$courseid] = $DB->get_fieldset_select(
                'course_sections', 'id', 'course = ?', [$courseid]);
        }
        if (!in_array((int) $cm->section, $validsectionsbycourse[$courseid], true)) {
            $brokenbycourse[$courseid][] = $cm->id;
        }
    }
    if (empty($brokenbycourse)) {
        return;
    }

    require_once($CFG->dirroot . '/course/lib.php');
    foreach ($brokenbycourse as $courseid => $cmids) {
        foreach ($cmids as $cmid) {
            course_add_cm_to_section($courseid, $cmid, 0);
        }
        rebuild_course_cache($courseid, true);
    }
}

/**
 * Devuelve los cmid de los cuestionarios de Moodle asociados a una lista de tests.
 *
 * Se resuelven ANTES de borrar los registros del plugin, porque una vez borrada la
 * fila de local_testmanager_tests ya no se puede saber a qué quiz apuntaba.
 *
 * @param array $testids identificadores de local_testmanager_tests.
 * @return array lista de cmid.
 */
function local_testmanager_get_test_cmids(array $testids) {
    global $DB;

    $testids = array_filter(array_map('intval', $testids));
    if (empty($testids)) {
        return [];
    }

    list($insql, $params) = $DB->get_in_or_equal($testids, SQL_PARAMS_NAMED);
    $tests = $DB->get_records_select('local_testmanager_tests', "id $insql", $params, '', 'id, quizid');

    $cmids = [];
    foreach ($tests as $test) {
        if (empty($test->quizid)) {
            continue;
        }
        $cm = get_coursemodule_from_instance('quiz', $test->quizid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $cmids[] = $cm->id;
        }
    }

    return $cmids;
}

/**
 * Elimina los cuestionarios nativos de Moodle asociados a tests ya borrados.
 *
 * Debe llamarse SIEMPRE fuera de una transacción delegada: course_delete_module()
 * dispara eventos, borra ficheros y toca la caché del curso, y eso no debe quedar
 * atrapado dentro de una transacción que aún puede revertirse.
 *
 * @param array $cmids lista de cmid obtenida con local_testmanager_get_test_cmids().
 * @return void
 */
function local_testmanager_delete_quiz_modules(array $cmids) {
    global $CFG;

    if (empty($cmids)) {
        return;
    }

    require_once($CFG->dirroot . '/course/lib.php');

    foreach ($cmids as $cmid) {
        try {
            course_delete_module($cmid);
        } catch (\Throwable $e) {
            debugging('local_testmanager: no se pudo eliminar el cuestionario cmid ' . $cmid .
                ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}

/**
 * Elimina los vínculos maestro->cuestionario de tests/cuestionarios que se están borrando,
 * para que local_testmanager_test_links no acumule filas huérfanas.
 *
 * @param array $testids ids de local_testmanager_tests que se van a borrar.
 * @param array $cmids cmid de los cuestionarios de Moodle que se van a borrar.
 * @return void
 */
function local_testmanager_cleanup_test_links(array $testids, array $cmids) {
    global $DB;

    $testids = array_filter(array_map('intval', $testids));
    if (!empty($testids)) {
        list($insql, $params) = $DB->get_in_or_equal($testids);
        $DB->delete_records_select('local_testmanager_test_links', "masterid $insql", $params);
    }

    $cmids = array_filter(array_map('intval', $cmids));
    if (!empty($cmids)) {
        list($insql, $params) = $DB->get_in_or_equal($cmids);
        $DB->delete_records_select('local_testmanager_test_links', "cmid $insql", $params);
    }
}

/**
 * Id del test "maestro" del que $test es copia. Un test que nunca fue copiado a otro
 * curso es su propio maestro (masterid = su propio id).
 *
 * @param stdClass $test registro de local_testmanager_tests.
 * @return int
 */
function local_testmanager_get_master_id($test) {
    return !empty($test->masterid) ? (int) $test->masterid : (int) $test->id;
}

/**
 * Registra que las preguntas del test maestro $masterid ahora también viven en el
 * cuestionario $cmid (de cualquier curso), para poder sincronizar altas y bajas de
 * preguntas más adelante con local_testmanager_sync_master_test().
 *
 * @param int $masterid id de local_testmanager_tests que actúa como maestro.
 * @param int $cmid course_module id del cuestionario destino.
 * @return void
 */
function local_testmanager_link_master_to_cmid($masterid, $cmid) {
    global $DB;

    $masterid = (int) $masterid;
    $cmid = (int) $cmid;
    if (!$masterid || !$cmid) {
        return;
    }

    if (!$DB->record_exists('local_testmanager_test_links', ['masterid' => $masterid, 'cmid' => $cmid])) {
        $DB->insert_record('local_testmanager_test_links', [
            'masterid'    => $masterid,
            'cmid'        => $cmid,
            'timecreated' => time(),
        ]);
    }
}

/**
 * Devuelve, en orden, los slots de un cuestionario con su referencia de banco de preguntas.
 *
 * @param int $quizid id de {quiz}.
 * @return array registros con slotid, refid, questionbankentryid, version, slot.
 */
function local_testmanager_get_quiz_entry_ids($quizid) {
    global $DB;

    return $DB->get_records_sql("
        SELECT qr.id AS refid, qs.id AS slotid, qs.slot, qr.questionbankentryid, qr.version
          FROM {quiz_slots} qs
          JOIN {question_references} qr
            ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
         WHERE qs.quizid = ?
      ORDER BY qs.slot ASC", [$quizid]);
}

/**
 * Id de la pregunta (tabla {question}) correspondiente a la versión más reciente de un
 * questionbankentryid, que es la que hay que usar al agregar la pregunta a un cuestionario.
 *
 * @param int $entryid questionbankentryid.
 * @return int|false
 */
function local_testmanager_get_latest_questionid($entryid) {
    global $DB;

    return $DB->get_field_sql("
        SELECT qv.questionid
          FROM {question_versions} qv
         WHERE qv.questionbankentryid = ?
      ORDER BY qv.version DESC", [$entryid], IGNORE_MULTIPLE);
}

/**
 * Sincroniza todos los cuestionarios vinculados a un test maestro con el conjunto de
 * preguntas que tiene ACTUALMENTE el cuestionario del maestro: agrega los slots que falten
 * y quita los que ya no estén. Así, agregar o eliminar una pregunta de un test (desde el
 * banco nativo o desde el cuestionario maestro) se traslada automáticamente a todos los
 * cursos donde ese test se haya importado, en vez de quedar solo en el maestro.
 *
 * Usa la API pública de mod_quiz (quiz_add_quiz_question() y structure::remove_slot())
 * en vez de tocar quiz_slots/question_references a mano, para no dejar la estructura del
 * cuestionario destino en un estado inconsistente (numeración de slots, secciones, etc.).
 *
 * @param stdClass $mastertest registro de local_testmanager_tests que es su propio maestro.
 * @return void
 */
function local_testmanager_sync_master_test($mastertest) {
    global $DB, $CFG;

    if (empty($mastertest->quizid) || !$DB->record_exists('quiz', ['id' => $mastertest->quizid])) {
        return;
    }

    $links = $DB->get_records('local_testmanager_test_links', ['masterid' => $mastertest->id]);
    if (empty($links)) {
        return;
    }

    require_once($CFG->dirroot . '/mod/quiz/locallib.php');

    $masterentryids = array_map(function($r) {
        return (int) $r->questionbankentryid;
    }, array_values(local_testmanager_get_quiz_entry_ids($mastertest->quizid)));

    foreach ($links as $link) {
        $cm = get_coursemodule_from_id('quiz', $link->cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            // El cuestionario destino ya no existe: el vínculo quedó huérfano, se limpia.
            $DB->delete_records('local_testmanager_test_links', ['id' => $link->id]);
            continue;
        }

        $targetquizid = (int) $cm->instance;
        if ($targetquizid === (int) $mastertest->quizid) {
            continue;
        }

        $targetslots = local_testmanager_get_quiz_entry_ids($targetquizid);
        $targetslotbyentry = [];
        foreach ($targetslots as $ts) {
            $targetslotbyentry[(int) $ts->questionbankentryid] = $ts->slot;
        }

        $toremove = array_diff(array_keys($targetslotbyentry), $masterentryids);
        $toadd = array_diff($masterentryids, array_keys($targetslotbyentry));

        if (empty($toremove) && empty($toadd)) {
            continue;
        }

        try {
            if (!empty($toremove)) {
                // Se vuelve a construir structure() en cada vuelta (no solo el quiz_settings):
                // remove_slot() reordena los slots restantes puertas adentro del objeto, y
                // reutilizar la misma instancia para varias eliminaciones seguidas dejaba su
                // caché interna (slotsinorder) desincronizada de la base de datos.
                foreach ($toremove as $entryid) {
                    $quizobj = \mod_quiz\quiz_settings::create($targetquizid);
                    $structure = \mod_quiz\structure::create_for_quiz($quizobj);
                    $currentslot = $DB->get_field_sql("
                        SELECT qs.slot
                          FROM {quiz_slots} qs
                          JOIN {question_references} qr
                            ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                         WHERE qs.quizid = ? AND qr.questionbankentryid = ?",
                        [$targetquizid, $entryid], IGNORE_MISSING);
                    if ($currentslot) {
                        $structure->remove_slot($currentslot);
                    }
                }
            }

            if (!empty($toadd)) {
                $targetquiz = $DB->get_record('quiz', ['id' => $targetquizid], '*', MUST_EXIST);
                $targetquiz->cmid = $cm->id;
                foreach ($toadd as $entryid) {
                    $questionid = local_testmanager_get_latest_questionid($entryid);
                    if ($questionid) {
                        quiz_add_quiz_question($questionid, $targetquiz);
                    }
                }
            }

            $gradecalculator = \mod_quiz\quiz_settings::create($targetquizid)->get_grade_calculator();
            $gradecalculator->recompute_quiz_sumgrades();
        } catch (\Throwable $e) {
            // No se detiene la sincronización de los demás cursos por un fallo puntual
            // (por ejemplo, un cuestionario con intentos ya realizados no se puede editar).
            debugging('local_testmanager: no se pudo sincronizar cmid ' . $link->cmid .
                ' con el test maestro ' . $mastertest->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}

/**
 * Sincroniza todos los tests maestros (activos, no en papelera) que tengan al menos un
 * cuestionario vinculado. Pensada para llamarse al cargar local/testmanager/index.php,
 * igual que las auto-reparaciones que ya existen ahí.
 *
 * @return void
 */
function local_testmanager_sync_all_masters() {
    global $DB;

    $masters = $DB->get_records_sql("
        SELECT t.*
          FROM {local_testmanager_tests} t
          JOIN {local_testmanager_categories} c ON c.id = t.categoryid
         WHERE t.masterid = t.id
           AND c.is_trash = 0
           AND EXISTS (SELECT 1 FROM {local_testmanager_test_links} l WHERE l.masterid = t.id)");

    foreach ($masters as $mastertest) {
        local_testmanager_sync_master_test($mastertest);
    }
}

/**
 * Recalcula en vivo question_count (y timemodified si cambió) de una lista de tests,
 * contra el número real de slots que tiene su cuestionario en este momento. El valor
 * guardado en la tabla se congelaba al crear el test y nunca se actualizaba después.
 *
 * @param array $tests registros de local_testmanager_tests (se modifican in-place).
 * @return void
 */
function local_testmanager_recount_tests(array $tests) {
    global $DB;

    $byquizid = [];
    foreach ($tests as $t) {
        if (!empty($t->quizid)) {
            $byquizid[(int) $t->quizid][] = $t;
        }
    }
    if (empty($byquizid)) {
        return;
    }

    list($insql, $params) = $DB->get_in_or_equal(array_keys($byquizid));
    $counts = $DB->get_records_sql(
        "SELECT quizid, COUNT(*) AS c FROM {quiz_slots} WHERE quizid $insql GROUP BY quizid", $params);

    $now = time();
    foreach ($byquizid as $quizid => $testrows) {
        $livecount = isset($counts[$quizid]) ? (int) $counts[$quizid]->c : 0;
        foreach ($testrows as $t) {
            if ((int) $t->question_count !== $livecount) {
                $DB->update_record('local_testmanager_tests', (object) [
                    'id'             => $t->id,
                    'question_count' => $livecount,
                    'timemodified'   => $now,
                ]);
                $t->question_count = $livecount;
                $t->timemodified = $now;
            }
        }
    }
}

/**
 * Devuelve la categoría "Papelera" de un curso lógico, creándola si aún no existe.
 *
 * @param int $courseid identificador en local_testmanager_courses.
 * @return stdClass registro de la categoría papelera.
 */
function local_testmanager_get_trash_category($courseid) {
    global $DB;

    $trashcat = $DB->get_record('local_testmanager_categories', ['courseid' => $courseid, 'is_trash' => 1]);
    if ($trashcat) {
        return $trashcat;
    }

    $trashcatid = $DB->insert_record('local_testmanager_categories', [
        'courseid'    => $courseid,
        'name'        => 'Papelera',
        'is_trash'    => 1,
        'timecreated' => time(),
    ]);

    return $DB->get_record('local_testmanager_categories', ['id' => $trashcatid], '*', MUST_EXIST);
}

/**
 * Construye un modal de confirmación de borrado con la URL de acción ya incrustada en el botón.
 *
 * No depende de JavaScript propio: Bootstrap lo abre con data-toggle/data-target y el botón
 * "Eliminar" es un enlace normal con su sesskey, por lo que siempre navega.
 *
 * @param string $id id del elemento modal.
 * @param string $title título del modal.
 * @param string $icon clase de icono FontAwesome.
 * @param string $question pregunta principal (HTML ya escapado por el llamador).
 * @param string|null $warning texto del bloque de advertencia roja, o null para omitirlo.
 * @param moodle_url $actionurl URL a la que apunta el botón Eliminar.
 * @param string|null $note nota informativa en gris bajo la pregunta.
 * @return string HTML del modal.
 */
function local_testmanager_render_confirm_modal($id, $title, $icon, $question, $warning, moodle_url $actionurl, $note = null) {
    $html  = '<div class="modal fade" id="' . $id . '" tabindex="-1" role="dialog" aria-labelledby="' . $id . 'Label" aria-hidden="true">';
    $html .= '<div class="modal-dialog modal-dialog-centered" role="document">';
    $html .= '<div class="modal-content border-0 shadow-lg rounded-lg">';

    $html .= '<div class="modal-header border-bottom-0 pb-0 pt-4 px-4" style="background-color: #fdf2f2;">';
    $html .= '<div class="d-flex align-items-center">';
    $html .= '<div class="d-flex align-items-center justify-content-center rounded-circle p-2 mr-3 text-danger" ' .
        'style="width: 40px; height: 40px; background-color: #fde8e8;"><i class="fa ' . $icon . ' fa-lg"></i></div>';
    $html .= '<h5 class="modal-title font-weight-bold text-danger" id="' . $id . 'Label">' . $title . '</h5>';
    $html .= '</div>';
    $html .= '<button type="button" class="close text-muted" data-dismiss="modal" aria-label="Cerrar">' .
        '<span aria-hidden="true">&times;</span></button>';
    $html .= '</div>';

    $html .= '<div class="modal-body px-4 py-3" style="background-color: #fdf2f2;">';
    $html .= '<p class="text-dark mb-3" style="font-size: 0.95rem;">' . $question . '</p>';
    if ($note !== null) {
        $html .= '<p class="text-muted small mb-4">' . $note . '</p>';
    }
    if ($warning !== null) {
        $html .= '<div class="alert border border-danger bg-white text-danger rounded p-3 mb-4 small">' .
            '<i class="fa fa-exclamation-triangle mr-1"></i> ' . $warning . '</div>';
    }
    $html .= '<div class="d-flex justify-content-end">';
    $html .= '<button type="button" class="btn btn-light border rounded px-4 mr-2 text-dark font-weight-bold" ' .
        'data-dismiss="modal">Cancelar</button>';
    $html .= '<a href="' . $actionurl->out(false) . '" class="btn btn-danger rounded px-4 text-white font-weight-bold" ' .
        'style="background-color: #e53e3e; border-color: #e53e3e;">Eliminar</a>';
    $html .= '</div>';
    $html .= '</div></div></div></div>';

    return $html;
}

/**
 * Construye el modal de la papelera de un curso lógico con su contenido ya renderizado.
 *
 * @param stdClass $course registro de local_testmanager_courses.
 * @param array $trashedtests tests que hay en la papelera.
 * @param int $trashedcount número de tests reciclados.
 * @param int $trashedquestions total de preguntas contenidas en la papelera.
 * @param moodle_url $emptytrashurl URL para vaciar la papelera.
 * @return string HTML del modal.
 */
function local_testmanager_render_trash_modal($course, array $trashedtests, $trashedcount, $trashedquestions,
        moodle_url $emptytrashurl) {

    $id = 'modalPapelera-' . $course->id;

    $html  = '<div class="modal fade" id="' . $id . '" tabindex="-1" role="dialog" aria-labelledby="' . $id . 'Label" aria-hidden="true">';
    $html .= '<div class="modal-dialog modal-dialog-centered modal-lg" role="document">';
    $html .= '<div class="modal-content border-0 shadow-lg rounded-lg overflow-hidden">';

    $html .= '<div class="modal-header border-bottom px-4 pt-4 pb-3" style="background-color: #f4fbf7;">';
    $html .= '<div class="d-flex align-items-center w-100">';
    $html .= '<div class="d-flex align-items-center justify-content-center rounded p-2 mr-3 text-success" ' .
        'style="width: 42px; height: 42px; background-color: #e3f5ec;"><i class="fa fa-trash fa-lg"></i></div>';
    $html .= '<div><div class="d-flex align-items-center mb-1">' .
        '<span class="text-uppercase text-success font-weight-bold mr-2" style="font-size: 11px; letter-spacing: 0.5px;">' .
        'Papelera del Curso</span></div>';
    $html .= '<h5 class="modal-title font-weight-bold text-dark mb-0" id="' . $id . 'Label" style="font-size: 1.1rem;">' .
        format_string($course->name) . '</h5></div></div>';
    $html .= '<button type="button" class="close text-muted" data-dismiss="modal" aria-label="Cerrar">' .
        '<span aria-hidden="true">&times;</span></button>';
    $html .= '</div>';

    $html .= '<div class="modal-body px-4 py-3 bg-white">';
    $html .= '<div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">';
    $html .= '<small class="text-muted font-weight-bold"><i class="fa fa-exclamation-circle text-warning mr-1"></i> ' .
        $trashedcount . ($trashedcount == 1 ? ' test reciclado' : ' tests reciclados') .
        ' (' . $trashedquestions . ' preguntas) en esta papelera</small>';
    if ($trashedcount > 0) {
        $html .= '<a href="' . $emptytrashurl->out(false) . '" class="text-danger font-weight-bold small">' .
            'Vaciar Papelera</a>';
    }
    $html .= '</div>';

    $html .= '<div style="max-height: 350px; overflow-y: auto; padding-right: 4px;">';
    if (empty($trashedtests)) {
        $html .= '<p class="text-muted text-center py-3 mb-0">La papelera de este curso está vacía.</p>';
    } else {
        $html .= '<ul class="list-group">';
        foreach ($trashedtests as $test) {
            $restoreurl = new moodle_url('/local/testmanager/index.php',
                ['action' => 'restoretest', 'testid' => $test->id, 'sesskey' => sesskey()]);
            $purgeurl = new moodle_url('/local/testmanager/index.php',
                ['action' => 'purgetest', 'testid' => $test->id, 'sesskey' => sesskey()]);

            $html .= '<li class="list-group-item d-flex justify-content-between align-items-center">';
            $html .= '<div><strong>' . format_string($test->name) . '</strong><br>' .
                '<span class="badge mr-2" style="color: #4B8B2C !important; background-color: #D2ECBF !important; font-size: 13px; font-weight: 500; padding: 6px 10px;"></i> ' .
                $test->question_count . ' preguntas</span>' .
                '<small class="text-muted">Creado: ' . userdate($test->timecreated, get_string('strftimedate')) . '</small></div>';
            $html .= '<div class="d-flex align-items-center">';
            $html .= '<a href="' . $restoreurl->out(false) . '" class="btn btn-success btn-sm rounded text-white mr-2" style="background-color:#69BF3F">' .
                '<i class="fa fa-undo"></i> Restaurar</a>';
            $html .= '<a href="' . $purgeurl->out(false) . '" class="btn btn-outline-danger btn-sm rounded" ' .
                'title="Eliminar definitivamente"><i class="fa fa-times"></i></a>';
            $html .= '</div></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div></div>';

    $html .= '<div class="modal-footer border-top bg-light px-4 py-3">';
    $html .= '<button type="button" class="btn btn-light border rounded px-4 text-dark font-weight-bold shadow-sm" ' .
        'data-dismiss="modal">Cerrar Papelera</button>';
    $html .= '</div>';

    $html .= '</div></div></div>';

    return $html;
}

/**
 * Busca tests activos (no papelera) para el selector "Importar desde Banco de Preguntas".
 *
 * @param string $search texto libre a buscar en el nombre del test.
 * @param int $filtercourse id de local_testmanager_courses, 0 = todos.
 * @param int $filtercategory id de local_testmanager_categories, 0 = todas.
 * @return array registros de local_testmanager_tests con coursename y catname añadidos.
 */
function local_testmanager_search_bank_tests($search, $filtercourse, $filtercategory) {
    global $DB;

    $sql = "SELECT t.*, c.id AS courseid, c.name AS coursename, cat.name AS catname
              FROM {local_testmanager_tests} t
              JOIN {local_testmanager_categories} cat ON t.categoryid = cat.id
              JOIN {local_testmanager_courses} c ON cat.courseid = c.id
             WHERE cat.is_trash = 0";
    $params = [];

    if ($filtercourse > 0) {
        $sql .= " AND c.id = :courseid";
        $params['courseid'] = $filtercourse;
    }

    if ($filtercategory > 0) {
        $sql .= " AND cat.id = :catid";
        $params['catid'] = $filtercategory;
    }

    if (!empty($search)) {
        $sql .= " AND " . $DB->sql_like('t.name', ':search', false);
        $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
    }

    $sql .= " ORDER BY t.name ASC";

    $tests = $DB->get_records_sql($sql, $params);
    local_testmanager_recount_tests($tests);

    return $tests;
}

/**
 * Renderiza el listado de tarjetas de tests del selector "Importar desde Banco de Preguntas".
 *
 * @param array $tests resultado de local_testmanager_search_bank_tests().
 * @return string HTML del listado (o del estado vacío).
 */
function local_testmanager_render_bank_test_cards(array $tests) {
    if (empty($tests)) {
        return '<div class="text-center text-muted py-4 border rounded bg-light" style="font-size: 13px;">' .
            '<i class="fa fa-info-circle mb-1"></i> No hay tests creados con los filtros o criterios de búsqueda seleccionados.</div>';
    }

    // El check visual NO usa las clases custom-control/custom-control-label de Bootstrap: en el tema
    // instalado no pintan el tick (se ve el cuadrado relleno pero sin flecha), así que se construye
    // a mano con CSS propio (ver .bank-test-card en styles.css) apoyado solo en :checked.
    $html = '';
    foreach ($tests as $t) {
        $html .= '<div class="bank-test-card">';
        $html .= '<input type="checkbox" class="bank-test-checkbox" id="banktest_' . $t->id .
            '" name="selected_tests[]" value="' . $t->id . '">';
        $html .= '<label class="bank-test-card-inner" for="banktest_' . $t->id . '">';
        $html .= '<span class="bank-check-visual" aria-hidden="true"><i class="fa fa-check"></i></span>';
        $html .= '<span class="bank-test-info">';
        $html .= '<span class="bank-test-name">' . s($t->name) . '</span>';
        $html .= '<span class="bank-test-meta">Pertenece a ' . s($t->catname ?? 'N/D') . '</span>';
        $html .= '</span>';
        $html .= '<span class="badge badge-pill badge-light border text-info px-3 py-1 font-weight-bold" ' .
            'style="font-size: 11px;">' . ($t->question_count ?? 0) . ' preguntas</span>';
        $html .= '</label></div>';
    }

    return $html;
}

/**
 * Renderiza el modal "Importar Test desde Banco de Preguntas": filtros + listado inicial.
 *
 * El listado que se muestra al abrir el modal se genera en servidor con los mismos filtros
 * que usará después el AJAX (ajax/bank_search.php), para no duplicar la consulta ni el HTML.
 *
 * @return string HTML del cuerpo del modal.
 */
function local_testmanager_render_bank_modal_body() {
    global $DB;

    $courses = $DB->get_records('local_testmanager_courses', null, 'name ASC', 'id, name');
    $categories = $DB->get_records_select('local_testmanager_categories', 'is_trash = 0', null, 'name ASC', 'id, name, courseid');
    $tests = local_testmanager_search_bank_tests('', 0, 0);

    $html  = '<input type="hidden" id="id_bank_categoryid" value="0">';
    $html .= '<div class="input-group bg-white rounded border shadow-sm px-3 py-1 mb-3">';
    $html .= '<div class="input-group-prepend align-items-center border-0 bg-transparent"><i class="fa fa-search text-muted"></i></div>';
    $html .= '<input type="text" id="bank-search-input" class="form-control border-0 shadow-none" ' .
        'placeholder="Busca por nombre de test o palabra clave...">';
    $html .= '</div>';

    $html .= '<div class="row mb-3">';
    $html .= '<div class="col-md-6">';
    $html .= '<label class="small font-weight-bold text-muted"><i class="fa fa-book mr-1"></i> FILTRO CURSO</label>';
    $html .= '<select id="bank-filter-course" class="custom-select rounded border shadow-sm px-3" style="font-size: 13px;">';
    $html .= '<option value="0">Todos los Cursos</option>';
    foreach ($courses as $c) {
        $html .= '<option value="' . $c->id . '">' . s($c->name) . '</option>';
    }
    $html .= '</select></div>';

    $html .= '<div class="col-md-6">';
    $html .= '<label class="small font-weight-bold text-muted"><i class="fa fa-layer-group mr-1"></i> FILTRO CATEGORÍA</label>';
    $html .= '<select id="bank-filter-category" class="custom-select rounded border shadow-sm px-3" style="font-size: 13px;">';
    $html .= '<option value="0">Todas las Categorías</option>';
    foreach ($categories as $cat) {
        $html .= '<option value="' . $cat->id . '" data-courseid="' . $cat->courseid . '">' . s($cat->name) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '</div>';

    $html .= '<div class="d-flex justify-content-between align-items-center mb-2 px-1">';
    $html .= '<small class="text-muted font-weight-bold" id="bank-results-count">Lista de Tests (' . count($tests) . ' encontrados)</small>';
    $html .= '<a href="#" class="text-info font-weight-bold small text-decoration-none" id="bank-select-all">Seleccionar todos</a>';
    $html .= '</div>';

    $html .= '<div class="bank-tests-list" id="bank-tests-list" style="max-height: 280px; overflow-y: auto; padding-right: 4px;">';
    $html .= local_testmanager_render_bank_test_cards($tests);
    $html .= '</div>';

    $html .= '<div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">';
    $html .= '<small class="text-muted font-weight-bold" id="bank-selected-counter">0 tests seleccionados</small>';
    $html .= '<div>';
    $html .= '<button type="button" class="btn btn-light text-dark px-4 mr-2" data-dismiss="modal" ' .
        'style="border: 1px solid #ced4da; font-size: 13px;">Cancelar</button>';
    $html .= '<button type="button" id="bank-import-btn" class="btn text-white font-weight-bold px-4" disabled ' .
        'style="background-color: #0099B2; border: none; font-size: 13px;">Importar</button>';
    $html .= '</div></div>';

    return $html;
}

/**
 * Construye el HTML del modal "Importar desde Test Manager" usado en mod/quiz/edit.php.
 *
 * Es el mismo buscador/filtro de local_testmanager_render_bank_modal_body(), envuelto en
 * un modal de Bootstrap con su propio id para no chocar con el de index.php.
 *
 * @return string HTML del modal completo.
 */
function local_testmanager_render_quizedit_modal_html() {
    $modalbody = local_testmanager_render_bank_modal_body();

    $html = '';
    $html .= '<div class="modal fade" id="modalImportarBancoQuiz" tabindex="-1" role="dialog" ' .
        'aria-labelledby="modalImportarBancoQuizLabel" aria-hidden="true">';
    $html .= '  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">';
    $html .= '    <div class="modal-content border-0 shadow-lg rounded-lg">';
    $html .= '      <div class="modal-header border-0 pb-0 pt-4 px-4">';
    $html .= '        <div>';
    $html .= '          <h5 class="modal-title font-weight-bold text-dark" id="modalImportarBancoQuizLabel">' .
        'Importar preguntas desde Test Manager</h5>';
    $html .= '          <small class="text-muted">Las preguntas de los tests que selecciones se añadirán ' .
        'al final de este cuestionario.</small>';
    $html .= '        </div>';
    $html .= '        <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close">' .
        '<span aria-hidden="true">&times;</span></button>';
    $html .= '      </div>';
    $html .= '      <div class="modal-body px-4 py-3">' . $modalbody . '</div>';
    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
}

/**
 * En mod/quiz/edit.php, reemplaza la acción del enlace nativo "Del banco de preguntas"
 * (data-action="questionbank") del desplegable "Añadir" de cada página del cuestionario,
 * para que abra el modal de importación de Test Manager en vez del banco de preguntas
 * nativo de Moodle. No se añade un segundo ítem aparte, para no confundir al usuario con
 * dos formas distintas de importar preguntas. No se toca ningún archivo del core.
 *
 * Se engancha vía local_testmanager_extend_navigation() (callback estándar de Moodle,
 * llamado en todas las páginas), el mismo mecanismo que ya usa local_questionsearch en
 * esta misma instalación para cargar JS en mod/quiz/edit.php. Se usa
 * $PAGE->requires->js_amd_inline() para no depender de un build de AMD (grunt).
 *
 * El modal se construye e inserta por JS (no se echa HTML directamente desde el callback,
 * porque extend_navigation no tiene un punto de salida para HTML crudo).
 *
 * @param int $cmid course_module id del cuestionario que se está editando.
 */
function local_testmanager_inject_quizedit_widget($cmid) {
    global $PAGE;

    $cmid = (int) $cmid;
    $modalhtml = local_testmanager_render_quizedit_modal_html();

    $jscode = <<<'JS'
require(['jquery', 'core/notification'], function($, Notification) {
    // Si por cualquier motivo Moodle llega a llamar extend_navigation() más de una vez
    // en la misma carga de página, este bloque también se inyectaría más de una vez;
    // sin este guard, cada $(document).on(...) de aquí abajo quedaría registrado dos
    // veces y un solo clic dispararía el import por duplicado (preguntas repetidas).
    if (window.testmanagerQuizEditInit) {
        return;
    }
    window.testmanagerQuizEditInit = true;

    var CMID = __CMID__;
    var MODALHTML = __MODALHTML__;

    if (!$('#modalImportarBancoQuiz').length) {
        $('body').append(MODALHTML);
    }

    // Cada página del quiz tiene su propio desplegable nativo "Añadir", con un enlace
    // data-action="questionbank" ("Del banco de preguntas"). En vez de añadir un segundo
    // ítem aparte (confundía al usuario con dos formas de "importar"), reemplazamos la
    // acción de ese mismo enlace: al pulsarlo se abre el modal de Test Manager en lugar
    // del banco de preguntas nativo de Moodle.
    var openTestManagerModal = function() {
        $('#modalImportarBancoQuiz').modal('show');
    };

    // El core enlaza su propio manejador de clic (bubbling) sobre data-action="questionbank"
    // para abrir el banco nativo. Se intercepta en fase de captura, antes de que ese
    // manejador llegue a ejecutarse, y se detiene la propagación para que nunca se abra.
    document.addEventListener('click', function(e) {
        var target = e.target.closest && e.target.closest('a[data-action="questionbank"]');
        if (!target) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        var $trigger = $(target).closest('.dropdown').find('[data-toggle="dropdown"]');
        try {
            $trigger.dropdown('hide');
        } catch (err) {
            $(target).closest('.dropdown-menu').removeClass('show');
        }
        openTestManagerModal();
    }, true);

    var bankSelectedIds = [];
    var bankSearchTimer = null;

    var bankRefreshCounter = function() {
        var n = bankSelectedIds.length;
        $('#bank-selected-counter').text(n + (n === 1 ? ' test seleccionado' : ' tests seleccionados'));
        $('#bank-import-btn').prop('disabled', n === 0);
    };

    var bankApplySelectionToList = function() {
        $('#bank-tests-list .bank-test-checkbox').each(function() {
            $(this).prop('checked', bankSelectedIds.indexOf($(this).val()) !== -1);
        });
    };

    var bankFetchTests = function() {
        var params = {
            search: $('#bank-search-input').val(),
            filtercourse: $('#bank-filter-course').val(),
            filtercategory: $('#bank-filter-category').val(),
            sesskey: M.cfg.sesskey
        };
        $('#bank-tests-list').css('opacity', 0.5);
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/bank_search.php',
            method: 'GET',
            data: params,
            dataType: 'json'
        }).done(function(response) {
            if (response && response.status === 'ok') {
                $('#bank-tests-list').html(response.html);
                $('#bank-results-count').text('Lista de Tests (' + response.count + ' encontrados)');
                bankApplySelectionToList();
            }
        }).always(function() {
            $('#bank-tests-list').css('opacity', 1);
        });
    };

    $(document).on('show.bs.modal', '#modalImportarBancoQuiz', function() {
        bankSelectedIds = [];
        $('#bank-search-input').val('');
        $('#bank-filter-course').val('0');
        $('#bank-filter-category').val('0').find('option').show();
        bankRefreshCounter();
        bankFetchTests();
    });

    $(document).on('input', '#bank-search-input', function() {
        window.clearTimeout(bankSearchTimer);
        bankSearchTimer = window.setTimeout(bankFetchTests, 300);
    });

    $(document).on('change', '#bank-filter-course', function() {
        var courseid = $(this).val();
        $('#bank-filter-category option').each(function() {
            var optcourse = $(this).attr('data-courseid');
            $(this).toggle(typeof optcourse === 'undefined' || courseid === '0' || optcourse === courseid);
        });
        $('#bank-filter-category').val('0');
        bankFetchTests();
    });

    $(document).on('change', '#bank-filter-category', bankFetchTests);

    $(document).on('change', '.bank-test-checkbox', function() {
        var id = $(this).val();
        var checked = $(this).is(':checked');
        var pos = bankSelectedIds.indexOf(id);
        if (checked && pos === -1) {
            bankSelectedIds.push(id);
        } else if (!checked && pos !== -1) {
            bankSelectedIds.splice(pos, 1);
        }
        bankRefreshCounter();
    });

    $(document).on('click', '#bank-select-all', function(e) {
        e.preventDefault();
        var visible = $('#bank-tests-list .bank-test-checkbox');
        var allChecked = visible.length > 0 && visible.filter(':checked').length === visible.length;
        visible.each(function() {
            var id = $(this).val();
            var pos = bankSelectedIds.indexOf(id);
            if (allChecked) {
                if (pos !== -1) {
                    bankSelectedIds.splice(pos, 1);
                }
            } else if (pos === -1) {
                bankSelectedIds.push(id);
            }
        });
        bankApplySelectionToList();
        bankRefreshCounter();
    });

    $(document).on('click', '#bank-import-btn', function() {
        var button = $(this);
        if (bankSelectedIds.length === 0 || button.prop('disabled')) {
            return;
        }
        button.prop('disabled', true).text('Importando...');
        $.ajax({
            url: M.cfg.wwwroot + '/local/testmanager/ajax/import_bank_into_quiz.php',
            method: 'POST',
            data: {
                cmid: CMID,
                selected_tests: JSON.stringify(bankSelectedIds),
                sesskey: M.cfg.sesskey
            },
            dataType: 'json'
        }).done(function(response) {
            if (response && response.status === 'ok') {
                var msg = response.imported + (response.imported === 1 ?
                    ' test añadido al cuestionario.' : ' tests añadidos al cuestionario.');
                if (response.errors && response.errors.length) {
                    msg += ' Incidencias: ' + response.errors.join(' | ');
                }
                Notification.addNotification({
                    message: msg,
                    type: response.errors && response.errors.length ? 'warning' : 'success'
                });
                window.setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                Notification.addNotification({
                    message: (response && response.message) ? response.message : 'No se pudo completar la importación.',
                    type: 'error'
                });
                button.prop('disabled', false).text('Importar');
            }
        }).fail(function() {
            Notification.addNotification({message: 'Error de comunicación al importar.', type: 'error'});
            button.prop('disabled', false).text('Importar');
        });
    });
});
JS;

    $jscode = str_replace('__CMID__', (string) $cmid, $jscode);
    $jscode = str_replace('__MODALHTML__', json_encode($modalhtml), $jscode);

    $PAGE->requires->js_amd_inline($jscode);
}

/**
 * Callback estándar de Moodle: se llama en todas las páginas. Aquí solo actuamos en
 * mod/quiz/edit.php, para inyectar el widget de importación de Test Manager.
 *
 * A propósito NO se toca question/edit.php: esa es la pantalla nativa donde se editan y
 * eliminan preguntas ya existentes de un test, y el cliente necesita seguir teniendo acceso
 * a ella (ver "Sincronización con el banco de preguntas" en el README).
 *
 * No se toca ningún archivo del core: es el mismo mecanismo (extend_navigation) que ya
 * usa local_questionsearch en este Moodle para cargar JS en mod/quiz/edit.php.
 *
 * @param global_navigation $nav
 */
function local_testmanager_extend_navigation(global_navigation $nav) {
    global $PAGE;

    if ($PAGE->pagetype !== 'mod-quiz-edit') {
        return;
    }

    $cmid = optional_param('cmid', 0, PARAM_INT);
    if (!$cmid) {
        return;
    }

    if (!has_capability('local/testmanager:manage', context_system::instance())) {
        return;
    }

    local_testmanager_inject_quizedit_widget($cmid);
}

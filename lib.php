<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Funciones auxiliares de local_testmanager.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 */

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
    $html .= '<button type="button" class="btn btn-light border rounded-pill px-4 mr-2 text-dark font-weight-bold" ' .
        'data-dismiss="modal">Cancelar</button>';
    $html .= '<a href="' . $actionurl->out(false) . '" class="btn btn-danger rounded-pill px-4 text-white font-weight-bold" ' .
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
                '<span class="badge badge-info mr-2"><i class="fa fa-question-circle mr-1"></i> ' .
                $test->question_count . ' preguntas</span>' .
                '<small class="text-muted">Creado: ' . userdate($test->timecreated, get_string('strftimedate')) . '</small></div>';
            $html .= '<div class="d-flex align-items-center">';
            $html .= '<a href="' . $restoreurl->out(false) . '" class="btn btn-success btn-sm rounded-pill text-white mr-2">' .
                '<i class="fa fa-undo"></i> Restaurar</a>';
            $html .= '<a href="' . $purgeurl->out(false) . '" class="btn btn-outline-danger btn-sm rounded-pill" ' .
                'title="Eliminar definitivamente"><i class="fa fa-times"></i></a>';
            $html .= '</div></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div></div>';

    $html .= '<div class="modal-footer border-top bg-light px-4 py-3">';
    $html .= '<button type="button" class="btn btn-light border rounded-pill px-4 text-dark font-weight-bold shadow-sm" ' .
        'data-dismiss="modal">Cerrar Papelera</button>';
    $html .= '</div>';

    $html .= '</div></div></div>';

    return $html;
}

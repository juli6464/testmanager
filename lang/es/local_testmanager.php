<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Cadenas de idioma en español para local_testmanager.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Gestor de tests';
$string['testmanager:manage'] = 'Gestionar el banco de tests (cursos, categorías, tests y papelera)';

// Página de ajustes de administración.
$string['settings_pagename'] = 'Gestión de Tests';

// UI general.
$string['pagetitle'] = 'Banco de Preguntas';
$string['headertitle'] = 'Banco de Preguntas';
$string['createcourse'] = 'Crear Curso';
$string['newcategory'] = 'Nueva Categoría';
$string['importtestcsv'] = 'Importar Test CSV';
$string['importfrombank'] = 'Importar desde Banco';
$string['filtercourses'] = 'FILTRO CURSOS';
$string['allcourses'] = 'Todos los cursos';
$string['searchtests'] = 'Filtrar tests en las categorías...';
$string['trashofcourse'] = 'Papelera del Curso';
$string['emptytrash'] = 'Vaciar papelera';
$string['deletecourse'] = 'Eliminar Curso';
$string['deletecategory'] = 'Eliminar Categoría';
$string['deletetest'] = 'Eliminar Test';
$string['restoretest'] = 'Restaurar';
$string['purgetest'] = 'Eliminar definitivamente';
$string['viewtestinmoodle'] = 'Ver test en Moodle';
$string['gotoquiz'] = 'Ir al Cuestionario';
$string['nosearchresults'] = 'No se encontraron tests que coincidan con "{$a}".';

// Notificaciones.
$string['coursecreated'] = 'Curso creado exitosamente.';
$string['categorycreated'] = 'Categoría creada con éxito.';
$string['testimported'] = 'Cuestionario nativo creado, categorizado e integrado con éxito.';
$string['testmovedtotrash'] = 'Test movido a la Papelera del curso correctamente.';
$string['testalreadyintrash'] = 'El test ya se encuentra en la papelera.';
$string['testrestored'] = 'Test restaurado en la categoría "{$a}".';
$string['testnotintrash'] = 'Este test no está en la papelera.';
$string['norestoretarget'] = 'No hay ninguna categoría activa en este curso a la que restaurar el test.';
$string['trashemptied'] = 'La papelera ha sido vaciada ({$a} tests eliminados definitivamente).';
$string['testpurged'] = 'Test eliminado definitivamente.';
$string['coursedeleted'] = 'Curso eliminado correctamente.';
$string['categorydeleted'] = 'Categoría "{$a->name}" y sus {$a->count} tests eliminados correctamente.';
$string['cannotdeletetrash'] = 'No se puede eliminar la papelera del curso. Utilice "Vaciar papelera".';
$string['nocoursetrash'] = 'Este curso no tiene papelera.';
$string['invalidcategory'] = 'Error: No se especificó una categoría válida para guardar el test.';
$string['errordeletingcategory'] = 'Error al eliminar la categoría: {$a}';
$string['errordeletingcourse'] = 'Error al eliminar el curso: {$a}';
$string['errorempyingtrash'] = 'Error al vaciar la papelera: {$a}';
$string['errordeletingtest'] = 'Error al eliminar el test: {$a}';

// Privacidad.
$string['privacy:metadata'] = 'El plugin Gestor de tests no almacena datos personales. Solo guarda la estructura (cursos, categorías y tests) usada para organizar los cuestionarios de Moodle.';

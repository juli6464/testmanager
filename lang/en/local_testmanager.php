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
 * English language strings for local_testmanager.
 *
 * @package    local_testmanager
 * @copyright  2026 Julián David Alzate Cuervo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Test manager';
$string['testmanager:manage'] = 'Manage the test bank (courses, categories, tests and trash)';

// Admin settings page.
$string['settings_pagename'] = 'Test bank management';

// General UI.
$string['pagetitle'] = 'Question bank';
$string['headertitle'] = 'Question bank';
$string['createcourse'] = 'Create course';
$string['newcategory'] = 'New category';
$string['importtestcsv'] = 'Import test (CSV)';
$string['importfrombank'] = 'Import from bank';
$string['filtercourses'] = 'FILTER COURSES';
$string['allcourses'] = 'All courses';
$string['searchtests'] = 'Filter tests within categories...';
$string['trashofcourse'] = 'Course trash';
$string['emptytrash'] = 'Empty trash';
$string['deletecourse'] = 'Delete course';
$string['deletecategory'] = 'Delete category';
$string['deletetest'] = 'Delete test';
$string['restoretest'] = 'Restore';
$string['purgetest'] = 'Delete permanently';
$string['viewtestinmoodle'] = 'View test in Moodle';
$string['gotoquiz'] = 'Go to the quiz';
$string['nosearchresults'] = 'No tests found matching "{$a}".';

// Notifications.
$string['coursecreated'] = 'Course created successfully.';
$string['categorycreated'] = 'Category created successfully.';
$string['testimported'] = 'Native quiz created, categorised and integrated successfully.';
$string['testmovedtotrash'] = 'Test moved to the course trash successfully.';
$string['testalreadyintrash'] = 'This test is already in the trash.';
$string['testrestored'] = 'Test restored to category "{$a}".';
$string['testnotintrash'] = 'This test is not in the trash.';
$string['norestoretarget'] = 'There is no active category in this course to restore the test to.';
$string['trashemptied'] = 'The trash has been emptied ({$a} tests permanently deleted).';
$string['testpurged'] = 'Test permanently deleted.';
$string['coursedeleted'] = 'Course deleted successfully.';
$string['categorydeleted'] = 'Category "{$a->name}" and its {$a->count} tests were deleted successfully.';
$string['cannotdeletetrash'] = 'The course trash cannot be deleted. Use "Empty trash" instead.';
$string['nocoursetrash'] = 'This course has no trash category.';
$string['invalidcategory'] = 'Error: no valid destination category was specified for the test.';
$string['errordeletingcategory'] = 'Error deleting the category: {$a}';
$string['errordeletingcourse'] = 'Error deleting the course: {$a}';
$string['errorempyingtrash'] = 'Error emptying the trash: {$a}';
$string['errordeletingtest'] = 'Error deleting the test: {$a}';

// Privacy.
$string['privacy:metadata'] = 'The Test manager plugin does not store any personal data. It only stores the structure (courses, categories and tests) used to organise Moodle quizzes.';

<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('root', new admin_externalpage(
        'local_testmanager',
        'Gestión de Tests',
        new moodle_url('/local/testmanager/index.php'),
        'moodle/site:config'
    ));
}
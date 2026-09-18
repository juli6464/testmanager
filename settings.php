<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('root', new admin_externalpage(
        'local_testmanager',
        get_string('settings_pagename', 'local_testmanager'),
        new moodle_url('/local/testmanager/index.php'),
        'moodle/site:config'
    ));
}
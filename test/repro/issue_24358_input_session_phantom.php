<?php
// Zend: INPUT_SESSION undefined; INPUT_GET…INPUT_SERVER defined (#24358)
echo defined('INPUT_SESSION') ? ('DEF='.INPUT_SESSION) : 'undefined', "\n";
foreach (['INPUT_GET', 'INPUT_POST', 'INPUT_COOKIE', 'INPUT_ENV', 'INPUT_SERVER', 'INPUT_REQUEST'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'U', "\n";
}

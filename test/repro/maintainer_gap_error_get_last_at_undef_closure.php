<?php

error_reporting(E_ALL);
error_clear_last();
$fn = function () {
    @ $undef;
    $e = error_get_last();
    echo 'type=', $e['type'] ?? 'none', "\n";
    echo 'msg=', $e['message'] ?? 'none', "\n";
};
$fn();

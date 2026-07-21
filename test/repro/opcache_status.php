<?php

$st = opcache_get_status(false);
var_export($st);
echo "\n";
var_export(is_array($st));
echo "\n";
$cfg = opcache_get_configuration();
var_export(is_array($cfg));
echo "\n";
if (is_array($cfg)) {
    var_export(isset($cfg['directives']['opcache.enable']));
    echo "\n";
}
var_export(opcache_reset());
echo "\n";

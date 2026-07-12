<?php

$st = opcache_get_status(false);
var_export($st !== false);
echo "\n";
if (is_array($st)) {
    var_export(isset($st['opcache_enabled']));
    echo "\n";
    var_export($st['opcache_enabled']);
    echo "\n";
    var_export(array_keys($st));
    echo "\n";
}
$cfg = opcache_get_configuration();
var_export(is_array($cfg));
echo "\n";
if (is_array($cfg)) {
    var_export(isset($cfg['directives']['opcache.enable']));
    echo "\n";
}
var_export(opcache_reset());
echo "\n";

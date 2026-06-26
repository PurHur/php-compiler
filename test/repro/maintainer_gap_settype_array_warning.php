<?php
$a = [1, 2];
settype($a, 'string');
var_export($a);
echo "\n";
var_export(error_get_last()['message'] ?? null);
echo "\n";

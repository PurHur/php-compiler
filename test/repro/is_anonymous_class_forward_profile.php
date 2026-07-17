<?php
declare(strict_types=1);

var_export(function_exists('isAnonymousClass'));
echo "\n";
var_export(isAnonymousClass(new class {}));
echo "\n";
var_export(isAnonymousClass(new stdClass()));
echo "\n";
$anon = new class extends stdClass {};
var_export(isAnonymousClass($anon));
echo "\n";

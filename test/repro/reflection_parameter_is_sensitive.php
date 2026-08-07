<?php
// #28528 — isSensitive absent on every profile (including 8.4).
// See also test/repro/issue_28528_reflectionparameter_issensitive_phantoms_84.php
var_export(method_exists(ReflectionParameter::class, 'isSensitive'));
echo "\n";
var_export(method_exists(ReflectionParameter::class, 'isSensitiveParameter'));
echo "\n";

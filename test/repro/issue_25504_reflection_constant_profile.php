<?php
// Repro #25504 — ReflectionConstant phantom gate vs Zend 8.2 / PROFILE≥8.3
var_export(class_exists('ReflectionConstant', false));
echo "\n";
if (class_exists('ReflectionConstant', false)) {
    define('FOO_RC_25504', 99);
    $ref = new ReflectionConstant('FOO_RC_25504');
    echo $ref->getName(), '=', $ref->getValue(), "\n";
}

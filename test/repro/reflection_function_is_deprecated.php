<?php
#[\Deprecated(message: 'old fn', since: '8.4')]
function dep(): void {}

function control(): void {}

$rf = new ReflectionFunction('dep');
var_export(method_exists($rf, 'isDeprecated') ? $rf->isDeprecated() : 'missing');
echo "\n";
$rc = new ReflectionFunction('control');
var_export(method_exists($rc, 'isDeprecated') ? $rc->isDeprecated() : 'missing');
echo "\n";

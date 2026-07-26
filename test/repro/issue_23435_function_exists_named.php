<?php
var_export(function_exists(function: 'strlen'));
echo PHP_EOL;
$rf = new ReflectionFunction('function_exists');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}

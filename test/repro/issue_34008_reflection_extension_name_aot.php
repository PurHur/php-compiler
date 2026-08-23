<?php
// Repro #34008 — AOT ReflectionExtension::$name after construct (Zend public name).
$r = new ReflectionExtension('standard');
echo $r->name, PHP_EOL;
echo $r->getName(), PHP_EOL;

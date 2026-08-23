<?php
// Repro #34003 — AOT ReflectionExtension::getName() after construct.
$r = new ReflectionExtension('standard');
echo $r->getName(), PHP_EOL;

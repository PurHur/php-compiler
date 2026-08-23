<?php

class C
{
}

$ru = new ReflectionClass(C::class);
$rr = new ReflectionClass(ReflectionClass::class);
$rd = new ReflectionClass(DateTime::class);
$rs = new ReflectionClass(stdClass::class);

var_export($ru->getExtensionName());
echo "\n";
var_export($rr->getExtensionName());
echo "\n";
var_export($rd->getExtensionName());
echo "\n";
var_export($rs->getExtensionName());
echo "\n";

<?php

/** Class doc */
class UserSrc
{
}

$ru = new ReflectionClass(UserSrc::class);
$ri = new ReflectionClass(stdClass::class);

echo var_export($ru->getStartLine(), true), "\n";
echo var_export($ru->getEndLine(), true), "\n";
echo var_export($ru->getDocComment(), true), "\n";
var_dump($ri->getStartLine());
var_dump($ri->getEndLine());
var_dump($ri->getDocComment());

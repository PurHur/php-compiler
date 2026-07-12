<?php

declare(strict_types=1);

// #18326 — ReflectionExtension introspection API (ext/reflection/php_reflection.c)

$re = new ReflectionExtension('standard');
echo method_exists($re, 'getVersion') ? 'yes' : 'no', "\n";
echo method_exists($re, 'getFunctions') ? 'yes' : 'no', "\n";
echo method_exists($re, 'getClasses') ? 'yes' : 'no', "\n";
echo method_exists($re, 'getConstants') ? 'yes' : 'no', "\n";
echo $re->getVersion(), "\n";
echo count($re->getFunctions()), "\n";
echo count($re->getClasses()), "\n";
echo count($re->getConstants()) > 0 ? 'yes' : 'no', "\n";

$spl = new ReflectionExtension('spl');
echo count($spl->getClasses()) > 0 ? 'yes' : 'no', "\n";

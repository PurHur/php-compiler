<?php
declare(strict_types=1);

// Issue #9759 — ReflectionClassConstant::isDeprecated() + fetch notice (Zend/zend_constants.c).
class C {
    #[\Deprecated(message: 'Old const', since: '8.4')]
    public const X = 1;
    public const Y = 2;
}

$rc = new ReflectionClassConstant(C::class, 'X');
var_export($rc->isDeprecated());
echo "\n";
$control = new ReflectionClassConstant(C::class, 'Y');
var_export($control->isDeprecated());
echo "\n";

ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
echo C::X, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "dep\n" : "no\n";

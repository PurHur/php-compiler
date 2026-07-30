<?php
/**
 * Issue #25472 — highlight_string/file return string|bool; substr_count length ?int=null;
 * preg_quote delimiter ?string=null (php-src basic_functions.stub.php / string.stub.php).
 */
declare(strict_types=1);

foreach (['highlight_string', 'highlight_file'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}

$r = new ReflectionFunction('substr_count');
$p = $r->getParameters()[3];
echo 'substr_count length type=', $p->hasType() ? (string) $p->getType() : 'NONE',
    ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a',
    "\n";
echo 'substr_count=', substr_count('aaa', 'a'), "\n";

$r = new ReflectionFunction('preg_quote');
$p = $r->getParameters()[1];
echo 'preg_quote delimiter type=', $p->hasType() ? (string) $p->getType() : 'NONE',
    ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a',
    "\n";
echo 'preg_quote=', preg_quote('a.b'), "\n";

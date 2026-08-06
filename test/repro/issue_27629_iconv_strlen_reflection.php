<?php
// Repro #27629 — iconv_strlen Reflection ?string $encoding → int|false (iconv.stub.php)
$r = new ReflectionFunction('iconv_strlen');
$params = $r->getParameters();
echo 'argc=', count($params), "\n";
echo 'p0=', $params[0]->getName(), ':', $params[0]->getType() ? (string) $params[0]->getType() : 'none',
    ' allows_null=', $params[0]->allowsNull() ? '1' : '0', "\n";
echo 'p1=', $params[1]->getName(), ':', $params[1]->getType() ? (string) $params[1]->getType() : 'none',
    ' allows_null=', $params[1]->allowsNull() ? '1' : '0',
    $params[1]->isOptional() ? ' =opt' : '', "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'len=', iconv_strlen('café', encoding: 'UTF-8'), "\n";
echo 'len_null_enc=', iconv_strlen('café', encoding: null), "\n";

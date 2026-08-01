<?php
// Repro #24970 — htmlentities() Reflection defaults match Zend (flags=11, encoding=NULL, double_encode=true)
$r = new ReflectionFunction('htmlentities');
$ok = true;
$params = $r->getParameters();
if (1 !== $r->getNumberOfRequiredParameters() || 4 !== $r->getNumberOfParameters()) {
    $ok = false;
}
if (!$r->hasReturnType() || 'string' !== (string) $r->getReturnType()) {
    $ok = false;
}
if ('string' !== (string) $params[0]->getType() || $params[0]->isOptional()) {
    $ok = false;
}
$flags = $params[1];
if ('int' !== (string) $flags->getType() || !$flags->isOptional() || !$flags->isDefaultValueAvailable() || 11 !== $flags->getDefaultValue()) {
    $ok = false;
}
$encoding = $params[2];
if ('?string' !== (string) $encoding->getType() || !$encoding->isOptional() || !$encoding->isDefaultValueAvailable() || null !== $encoding->getDefaultValue()) {
    $ok = false;
}
$double = $params[3];
if ('bool' !== (string) $double->getType() || !$double->isOptional() || !$double->isDefaultValueAvailable() || true !== $double->getDefaultValue()) {
    $ok = false;
}
$ok = $ok
    && '&lt;&gt;&quot;&#039;' === htmlentities('<>"\'')
    && '&lt;&gt;&quot;&#039;' === htmlentities(string: '<>"\'', flags: 11, encoding: null, double_encode: true);
echo $ok ? "ok\n" : "fail\n";

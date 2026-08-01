<?php
// Repro #26282 — mb_ucfirst/mb_lcfirst Reflection types + encoding default (PROFILE=8.4)
$ok = true;
foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
    $r = new ReflectionFunction($fn);
    if (1 !== $r->getNumberOfRequiredParameters() || 2 !== $r->getNumberOfParameters()) {
        $ok = false;
    }
    if (!$r->hasReturnType() || 'string' !== (string) $r->getReturnType()) {
        $ok = false;
    }
    $params = $r->getParameters();
    if ('string' !== (string) $params[0]->getType() || $params[0]->isOptional()) {
        $ok = false;
    }
    $enc = $params[1];
    if ('?string' !== (string) $enc->getType() || !$enc->isOptional() || !$enc->isDefaultValueAvailable() || null !== $enc->getDefaultValue()) {
        $ok = false;
    }
}
$ok = $ok
    && 'Ab' === mb_ucfirst(string: 'ab', encoding: 'UTF-8')
    && 'Ab' === mb_ucfirst('ab')
    && 'aBC' === mb_lcfirst(string: 'ABC', encoding: 'UTF-8')
    && 'aBC' === mb_lcfirst('ABC');
echo $ok ? "ok\n" : "fail\n";

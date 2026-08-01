<?php
// Repro #26283 — mb_trim/ltrim/rtrim Reflection types + optional defaults (PROFILE=8.4)
$ok = true;
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    $r = new ReflectionFunction($fn);
    if (1 !== $r->getNumberOfRequiredParameters() || 3 !== $r->getNumberOfParameters()) {
        $ok = false;
    }
    if (!$r->hasReturnType() || 'string' !== (string) $r->getReturnType()) {
        $ok = false;
    }
    $params = $r->getParameters();
    if ('string' !== (string) $params[0]->getType() || $params[0]->isOptional()) {
        $ok = false;
    }
    foreach ([1, 2] as $i) {
        $p = $params[$i];
        if ('?string' !== (string) $p->getType() || !$p->isOptional() || !$p->isDefaultValueAvailable() || null !== $p->getDefaultValue()) {
            $ok = false;
        }
    }
}
$named = mb_trim(string: ' x ', characters: null, encoding: 'UTF-8');
$omit = mb_trim(' x ');
$ok = $ok && 'x' === $named && 'x' === $omit
    && 'x ' === mb_ltrim(string: ' x ')
    && ' x' === mb_rtrim(string: ' x ');
echo $ok ? "ok\n" : "fail\n";

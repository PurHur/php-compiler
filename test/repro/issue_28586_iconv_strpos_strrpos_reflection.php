<?php
// Repro #28586 — iconv_strpos/iconv_strrpos Reflection int|false + ?string $encoding
foreach (['iconv_strpos', 'iconv_strrpos'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        if ($p->getName() === 'encoding') {
            echo '  encoding=', $p->hasType() ? (string) $p->getType() : 'untyped',
                ' allows_null=', $p->allowsNull() ? '1' : '0',
                $p->isOptional() ? ' =opt' : '',
                "\n";
        }
    }
}
echo 'pos=', var_export(iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, encoding: null), true), "\n";
echo 'rpos=', var_export(iconv_strrpos(haystack: 'abcb', needle: 'b', encoding: null), true), "\n";
echo 'miss=', var_export(iconv_strpos('abc', 'z', 0, 'UTF-8'), true), "\n";

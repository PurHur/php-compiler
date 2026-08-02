<?php
// #26721 — Dom\TokenList must match php-src stub: no __toString; (string) throws Error.
$doc = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>',
    LIBXML_NOERROR
);
$cl = $doc->getElementById('d')->classList;
echo 'class=', $cl::class, "\n";
echo 'toString=', method_exists($cl, '__toString') ? 'y' : 'n', "\n";
foreach (['entries', 'keys', 'values', 'forEach', 'getIterator'] as $m) {
    echo $m, '=', method_exists($cl, $m) ? 'y' : 'n', "\n";
}
try {
    echo 'cast=', (string) $cl, "\n";
} catch (Throwable $e) {
    echo 'cast_err=', $e::class, "\n";
}
$out = [];
foreach ($cl as $i => $t) {
    $out[] = $i . ':' . $t;
}
echo 'foreach=', implode(',', $out), "\n";
echo 'value=', $cl->value, "\n";

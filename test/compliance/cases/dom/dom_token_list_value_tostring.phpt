<?php
// Repro #20884 / #26721 — Dom\TokenList foreach yields tokens via IteratorAggregate.
// php-src stub has getIterator only — no entries/keys/values/forEach.
$doc = Dom\HTMLDocument::createFromString(
    '<!doctype html><html><body><div class="a b c"></div></body></html>',
    LIBXML_NOERROR
);
$tl = $doc->body->firstElementChild->classList;
echo 'class=', get_class($tl), ' Traversable=', $tl instanceof Traversable ? 'yes' : 'no', "\n";
foreach (['getIterator', 'entries', 'keys', 'values', 'forEach'] as $m) {
    echo $m, '=', method_exists($tl, $m) ? 'yes' : 'no', "\n";
}
$out = [];
foreach ($tl as $i => $t) {
    $out[] = $i . ':' . $t;
}
echo 'foreach=', implode(',', $out), "\n";

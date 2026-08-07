--TEST--
Dom\TokenList foreach via IteratorAggregate; no entries/keys/values/forEach (#26721, re-#20884)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#26721)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
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
?>
--EXPECT--
class=Dom\TokenList Traversable=yes
getIterator=yes
entries=no
keys=no
values=no
forEach=no
foreach=0:a,1:b,2:c

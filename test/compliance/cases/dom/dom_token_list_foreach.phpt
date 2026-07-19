--TEST--
Dom\TokenList foreach / IteratorAggregate + entries/keys/values/forEach (#20884, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#20884)');
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
echo 'class=', get_class($tl), "\n";
echo 'Traversable=', $tl instanceof Traversable ? 'yes' : 'no', "\n";
echo 'IteratorAggregate=', $tl instanceof IteratorAggregate ? 'yes' : 'no', "\n";
foreach (['getIterator', 'entries', 'keys', 'values', 'forEach'] as $m) {
    echo $m, '=', method_exists($tl, $m) ? 'yes' : 'no', "\n";
}
$out = [];
foreach ($tl as $i => $t) {
    $out[] = $i . ':' . $t;
}
echo 'foreach=', implode(',', $out), "\n";
$vals = [];
foreach ($tl->values() as $i => $t) {
    $vals[] = $i . ':' . $t;
}
echo 'values=', implode(',', $vals), "\n";
$keys = [];
foreach ($tl->keys() as $k) {
    $keys[] = (string) $k;
}
echo 'keys=', implode(',', $keys), "\n";
$ents = [];
foreach ($tl->entries() as $pair) {
    $ents[] = $pair[0] . ':' . $pair[1];
}
echo 'entries=', implode(',', $ents), "\n";
$seen = [];
$tl->forEach(function (string $token, int $index) use (&$seen): void {
    $seen[] = $index . ':' . $token;
});
echo 'forEach=', implode(',', $seen), "\n";
?>
--EXPECT--
class=Dom\TokenList
Traversable=yes
IteratorAggregate=yes
getIterator=yes
entries=yes
keys=yes
values=yes
forEach=yes
foreach=0:a,1:b,2:c
values=0:a,1:b,2:c
keys=0,1,2
entries=0:a,1:b,2:c
forEach=0:a,1:b,2:c

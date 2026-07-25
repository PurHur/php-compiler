--TEST--
stdlib Dom\TokenList dimension handlers (#23006, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#23006)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><p class="a b 0" id="p">x</p></body></html>'
);
$tl = $html->getElementById('p')->classList;

echo $tl[0], ' ', $tl[1], ' ', $tl[2], "\n";
echo $tl->item(0), ' ', $tl->item(1), ' ', $tl->item(2), "\n";
echo isset($tl[0]) ? 'yes' : 'no', ' ', isset($tl[9]) ? 'yes' : 'no', "\n";
echo $tl[9] === null ? 'null' : 'tok', ' ', $tl['1'] === null ? 'null' : $tl['1'], "\n";
echo empty($tl[0]) ? 'e' : 'ne', ' ', empty($tl[2]) ? 'e' : 'ne', ' ', empty($tl[9]) ? 'e' : 'ne', "\n";
echo $tl instanceof ArrayAccess ? 'aa' : 'no-aa', "\n";

try {
    $tl[0] = 'x';
    echo "write-ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo $tl['foo'];
    echo "str-ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
a b 0
a b 0
yes no
null b
ne e e
no-aa
Cannot use object of type Dom\TokenList as array
Cannot access offset of type string on Dom\TokenList

--TEST--
Dom\AdjacentPosition — living enum-only; legacy string-only (#20782 follow-up)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20782)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('Dom\\AdjacentPosition') ? "enum\n" : "no_enum\n";
echo Dom\AdjacentPosition::BeforeBegin->value, "\n";

$d = Dom\HTMLDocument::createEmpty();
$html = $d->createElement('html');
$body = $d->createElement('body');
$d->append($html);
$html->append($body);
$p = $d->createElement('p');
$body->append($p);
$n = $d->createElement('i');
$p->insertAdjacentElement(Dom\AdjacentPosition::AfterBegin, $n);
echo $p->firstElementChild !== null ? "living_enum\n" : "living_bad\n";

$n2 = $d->createElement('b');
try {
    $p->insertAdjacentElement('beforeend', $n2);
    echo "living_string_ok\n";
} catch (TypeError $e) {
    echo "living_string_TypeError\n";
}

$legacy = new DOMDocument();
$legacy->loadXML('<root><a/></root>');
$el = $legacy->documentElement;
$x = $legacy->createElement('x');
$el->insertAdjacentElement('beforeend', $x);
echo "legacy_string\n";

$y = $legacy->createElement('y');
try {
    $el->insertAdjacentElement(Dom\AdjacentPosition::AfterEnd, $y);
    echo "legacy_enum_ok\n";
} catch (TypeError $e) {
    echo "legacy_enum_TypeError\n";
}
?>
--EXPECT--
enum
beforebegin
living_enum
living_string_TypeError
legacy_string
legacy_enum_TypeError

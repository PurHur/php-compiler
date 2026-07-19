--TEST--
Dom\AdjacentPosition enum + insertAdjacentElement living/legacy (#20782)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('Dom\\AdjacentPosition') ? "enum yes\n" : "enum no\n";
echo Dom\AdjacentPosition::BeforeBegin->value, "\n";
echo Dom\AdjacentPosition::AfterEnd->name, "\n";

$d = Dom\HTMLDocument::createEmpty();
$html = $d->createElement('html');
$body = $d->createElement('body');
$d->append($html);
$html->append($body);
$p = $d->createElement('p');
$body->append($p);
$n = $d->createElement('i');
$p->insertAdjacentElement(Dom\AdjacentPosition::AfterBegin, $n);
echo $p->firstElementChild !== null ? "living ok\n" : "living bad\n";

try {
    $p->insertAdjacentElement('beforeend', $d->createElement('b'));
    echo "living string ok\n";
} catch (TypeError $e) {
    echo "living string TypeError\n";
}

$legacy = new DOMDocument();
$legacy->loadXML('<root><a/></root>');
$el = $legacy->documentElement;
try {
    $el->insertAdjacentElement(Dom\AdjacentPosition::BeforeBegin, $legacy->createElement('i'));
    echo "legacy enum ok\n";
} catch (TypeError $e) {
    echo "legacy enum TypeError\n";
}
$el->insertAdjacentElement('beforeend', $legacy->createElement('x'));
echo "legacy string ok\n";
?>
--EXPECT--
enum yes
beforebegin
AfterEnd
living ok
living string TypeError
legacy enum TypeError
legacy string ok

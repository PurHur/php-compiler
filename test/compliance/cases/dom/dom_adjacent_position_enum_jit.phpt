--TEST--
Dom\AdjacentPosition enum + insertAdjacentElement living/legacy JIT (#20782)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('Dom\\AdjacentPosition') ? "enum yes\n" : "enum no\n";
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
echo $p->firstElementChild !== null ? "living ok\n" : "living bad\n";

$legacy = new DOMDocument();
$legacy->loadXML('<root><a/></root>');
$x = $legacy->createElement('x');
$legacy->documentElement->insertAdjacentElement('beforeend', $x);
echo "legacy string ok\n";
?>
--EXPECT--
enum yes
beforebegin
living ok
legacy string ok

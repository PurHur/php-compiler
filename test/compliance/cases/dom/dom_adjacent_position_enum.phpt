--TEST--
Dom\AdjacentPosition enum + insertAdjacent* enum where (#20782)
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
echo Dom\AdjacentPosition::AfterBegin->value, "\n";
echo Dom\AdjacentPosition::BeforeEnd->value, "\n";
echo Dom\AdjacentPosition::AfterEnd->value, "\n";

$doc = Dom\HTMLDocument::createFromString('<p id="p">hi</p>');
$p = $doc->getElementById('p');
$n = $doc->createElement('i');
$p->insertAdjacentElement(Dom\AdjacentPosition::BeforeBegin, $n);
echo $doc->body->firstElementChild->tagName, "\n";

$p = $doc->getElementById('p');
$p->insertAdjacentText(Dom\AdjacentPosition::AfterBegin, 'X');
$html = '<b>Y</b>';
$p->insertAdjacentHTML(Dom\AdjacentPosition::BeforeEnd, $html);
$tc = $p->textContent;
echo (str_contains($tc, 'X') && str_contains($tc, 'Y')) ? "mut\n" : "no_mut\n";

$legacy = new DOMDocument();
$legacy->loadHTML('<div id="d">z</div>', LIBXML_NOERROR);
$el = $legacy->getElementById('d');
$span = $legacy->createElement('span');
$el->insertAdjacentElement(Dom\AdjacentPosition::AfterEnd, $span);
echo "legacy\n";
?>
--EXPECT--
enum
beforebegin
afterbegin
beforeend
afterend
i
mut
legacy

--TEST--
Stdlib: Dom\ living nodes allow dynamic props with E_DEPRECATED (#26566, re-#26055, ext/dom)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#26566)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set('error_reporting', '32767');

$d = Dom\HTMLDocument::createFromString('<p id="p">x</p>', LIBXML_NOERROR);
$p = $d->getElementById('p');
$p->totallyFake = 'v';
echo 'el_isset=', isset($p->totallyFake) ? '1' : '0', ' val=', $p->totallyFake, "\n";

// PROFILE=8.4: outerHTML is not a declared Dom\Element prop (8.5+); write is dynamic (#22482).
$p->outerHTML = '<span>y</span>';
echo 'outer_isset=', isset($p->outerHTML) ? '1' : '0', ' val=', $p->outerHTML, "\n";

$d->foo = 1;
echo 'doc_isset=', isset($d->foo) ? '1' : '0', ' val=', $d->foo, "\n";

$text = $p->firstChild;
$text->foo = 1;
echo 'text_isset=', isset($text->foo) ? '1' : '0', ' val=', $text->foo, "\n";

$xml = Dom\XMLDocument::createFromString('<r/>');
$xmlEl = $xml->documentElement;
$xmlEl->phpcDyn = 1;
echo 'xml_isset=', isset($xmlEl->phpcDyn) ? '1' : '0', ' val=', $xmlEl->phpcDyn, "\n";
?>
--EXPECTF--
PHP Deprecated:  Creation of dynamic property Dom\HTMLElement::$totallyFake is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property Dom\HTMLElement::$outerHTML is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property Dom\HTMLDocument::$foo is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property Dom\Text::$foo is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property Dom\Element::$phpcDyn is deprecated in %s on line %d
el_isset=1 val=v
outer_isset=1 val=<span>y</span>
doc_isset=1 val=1
text_isset=1 val=1
xml_isset=1 val=1

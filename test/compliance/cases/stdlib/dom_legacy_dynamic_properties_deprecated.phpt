--TEST--
Stdlib: legacy DOMElement/DOMDocument allow dynamic props with E_DEPRECATED (#26265, ext/dom)
--FILE--
<?php
ini_set('error_reporting', '32767');

$d = new DOMDocument();
$d->loadXML('<a id="x"/>');
$el = $d->documentElement;

$el->foo = 1;
echo 'el_isset=', isset($el->foo) ? '1' : '0', ' val=', $el->foo, "\n";

$d->bar = 2;
echo 'doc_isset=', isset($d->bar) ? '1' : '0', ' val=', $d->bar, "\n";

$attr = $el->getAttributeNode('id');
$attr->baz = 3;
echo 'attr_isset=', isset($attr->baz) ? '1' : '0', ' val=', $attr->baz, "\n";
?>
--EXPECTF--
PHP Deprecated:  Creation of dynamic property DOMElement::$foo is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property DOMDocument::$bar is deprecated in %s on line %d
PHP Deprecated:  Creation of dynamic property DOMAttr::$baz is deprecated in %s on line %d
el_isset=1 val=1
doc_isset=1 val=2
attr_isset=1 val=3

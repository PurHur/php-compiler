<?php
/**
 * #26752 — AOT DOM ChildNode after/before/replaceWith/remove (ext/dom/php_dom.stub.php).
 */
$d = new DOMDocument();
$d->loadXML('<root><a/></root>');
$a = $d->getElementsByTagName('a')->item(0);
$a->after($d->createElement('z'));
echo trim($d->saveXML($d->documentElement)), "\n";

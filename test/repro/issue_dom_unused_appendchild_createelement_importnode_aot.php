<?php

declare(strict_types=1);

/**
 * Unused `$dst->appendChild($dst->createElement('root'))` before importNode must not
 * re-emit createElement/appendChild (Zend: cn=1; saveXML has no trailing `<root/>`).
 *
 * #34405 / re-#24571 / #34302
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode) / appendChild
 */
$src = new DOMDocument();
$src->loadXML('<a><b>1</b></a>');
$dst = new DOMDocument();
$dst->appendChild($dst->createElement('root'));
$imp = $dst->importNode($src->documentElement, true);
$dst->documentElement->appendChild($imp);
echo 'cn='.$dst->childNodes->length."\n";
echo trim($dst->saveXML()), "\n";

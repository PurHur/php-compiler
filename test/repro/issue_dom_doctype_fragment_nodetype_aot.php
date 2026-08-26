<?php
declare(strict_types=1);

/**
 * #35168 — AOT createDocumentType / createDocumentFragment must seed nodeType.
 * php-src: XML_DOCUMENT_TYPE_NODE=10, XML_DOCUMENT_FRAG_NODE=11
 * (ext/dom/domimplementation.c createDocumentType; ext/dom/document.c createDocumentFragment).
 */
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html', '', '');
echo 'doctype_type=', $dt->nodeType, ' doctype_name=', $dt->nodeName, "\n";

$d = new DOMDocument();
$f = $d->createDocumentFragment();
echo 'frag_type=', $f->nodeType, ' frag_name=', $f->nodeName, "\n";

<?php

declare(strict_types=1);

/** Issue #14355 — DOMDocumentType name/publicId/systemId/nodeName (ext/dom/php_dom.c). */
$impl = new DOMImplementation();
$dt = $impl->createDocumentType(
    'html',
    '-//W3C//DTD HTML 4.01//EN',
    'http://www.w3.org/TR/html4/strict.dtd'
);
echo $dt->nodeName, '|', $dt->name, '|', $dt->publicId, '|', $dt->systemId, "\n";

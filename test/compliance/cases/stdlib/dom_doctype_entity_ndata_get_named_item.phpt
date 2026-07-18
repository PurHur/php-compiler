--TEST--
stdlib DOMDocumentType entities getNamedItem + SYSTEM/PUBLIC/NDATA (#20734, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$xml = '<!DOCTYPE r ['
    . '<!ENTITY e SYSTEM "http://example/x" NDATA gif>'
    . '<!ENTITY f "text">'
    . '<!ENTITY g PUBLIC "-//ex//EN" "http://example/g" NDATA gif>'
    . '<!ENTITY h SYSTEM "http://example/h">'
    . '<!NOTATION gif SYSTEM "image/gif">'
    . ']><r>&f;</r>';

$d = new DOMDocument();
@$d->loadXML($xml);
$dt = $d->doctype;
echo $dt->entities->length, "\n";
echo $dt->notations->length, "\n";

$e = $dt->entities->getNamedItem('e');
echo (int) ($e instanceof DOMEntity), "\n";
echo $e->systemId, "\n";
echo $e->notationName, "\n";
echo var_export($e->publicId, true), "\n";

$f = $dt->entities->getNamedItem('f');
echo (int) ($f instanceof DOMEntity), "\n";
echo var_export($f->notationName, true), "\n";

$g = $dt->entities->getNamedItem('g');
echo $g->publicId, "\n";
echo $g->systemId, "\n";
echo $g->notationName, "\n";

// External parsed (no NDATA): in map, but publicId/systemId/notationName are null (php-src entity.c).
$h = $dt->entities->getNamedItem('h');
echo (int) ($h instanceof DOMEntity), "\n";
echo var_export($h->systemId, true), "\n";
echo var_export($h->notationName, true), "\n";

$n = $dt->notations->getNamedItem('gif');
echo (int) ($n instanceof DOMNotation), "\n";
echo $n->systemId, "\n";
?>
--EXPECT--
4
1
1
http://example/x
gif
NULL
1
NULL
-//ex//EN
http://example/g
gif
1
NULL
NULL
1
image/gif

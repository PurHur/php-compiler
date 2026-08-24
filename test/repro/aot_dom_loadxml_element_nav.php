<?php

declare(strict_types=1);

/**
 * AOT: loadXML must wire ParentNode / NonDocumentTypeChildNode element-nav props (#34352).
 *
 * leftover of #34345 / #19431 — syncChildrenFromXml set nextSibling but not
 * firstElementChild / nextElementSibling.
 *
 * php-src: ext/dom/parentnode.c, ext/dom/nodelist.c
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$nes = $a->nextElementSibling;
$pes = $a->nextSibling->previousElementSibling;
$fec = $r->firstElementChild;
$lec = $r->lastElementChild;
$cnt = $r->childElementCount;

echo 'nes=', $nes->tagName, "\n";
echo 'pes=', $pes->tagName, "\n";
echo 'fec=', $fec->tagName, "\n";
echo 'lec=', $lec->tagName, "\n";
echo 'count=', $cnt, "\n";

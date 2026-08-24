<?php
declare(strict_types=1);

/**
 * AOT: loadXML firstChild->splitText must not abort as object::splittext (#34311, re-#32362).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, splitText) → xmlTextSplitText.
 */
$d = new DOMDocument();
$d->loadXML('<r>hello</r>');
$t = $d->documentElement->firstChild;
$t2 = $t->splitText(2);
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo 'xml=', $d->saveXML($d->documentElement), "\n";
echo 't=', $t->data, ' t2=', $t2->data, "\n";

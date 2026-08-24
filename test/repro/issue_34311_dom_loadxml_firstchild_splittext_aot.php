<?php
declare(strict_types=1);

/**
 * AOT: loadXML firstChild->splitText must not abort as object::splittext (#34311).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, splitText) → xmlTextSplitText.
 */
$d = new DOMDocument();
$d->loadXML('<r>hello</r>');
$t = $d->documentElement->firstChild;
$t2 = $t->splitText(2);
echo 'len=', strlen($t->data), "\n";
echo 't2=', $t2->data, "\n";
echo $d->saveXML();

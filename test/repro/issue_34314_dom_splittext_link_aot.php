<?php
declare(strict_types=1);

/**
 * AOT: loadXML firstChild->splitText must link suffix as nextSibling (#34314).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, splitText) → xmlTextSplitText.
 */
$d = new DOMDocument();
$d->loadXML('<r>hello</r>');
$t = $d->documentElement->firstChild;
$t2 = $t->splitText(2);
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo 't=', $t->data, ' t2=', $t2->data, "\n";
echo 'next=', ($t->nextSibling ? $t->nextSibling->data : 'null'), "\n";
echo 'parent=', ($t2->parentNode ? $t2->parentNode->nodeName : 'null'), "\n";

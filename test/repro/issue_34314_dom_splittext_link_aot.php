<?php
declare(strict_types=1);

/**
 * AOT: in-tree splitText must link the suffix (childNodes / nextSibling) (#34314, re-#34311).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, splitText) → xmlTextSplitText.
 */
$d = new DOMDocument();
$d->loadXML('<r>hello</r>');
$t = $d->documentElement->firstChild;
$t2 = $t->splitText(2);
echo 'childNodes=', $d->documentElement->childNodes->length, "\n";
echo 'next=', $t->nextSibling ? $t->nextSibling->data : 'null', "\n";
echo 'parent=', $t2->parentNode ? $t2->parentNode->nodeName : 'null', "\n";

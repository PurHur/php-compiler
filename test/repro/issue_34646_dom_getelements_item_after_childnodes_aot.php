<?php
/**
 * #34646 — AOT held getElementsByTagName('*') item() after childNodes fetch.
 * php-src: ext/dom/nodelist.c live list; childNodes must not poison tag-list item().
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->getElementsByTagName('*');
$_ = $d->documentElement->childNodes->item(1);
echo 'len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo $list->item($i)->nodeName, ',';
}
echo "\n";

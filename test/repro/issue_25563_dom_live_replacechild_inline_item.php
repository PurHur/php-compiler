<?php
declare(strict_types=1);
/**
 * Issue #25563 — replaceChild(createElement(...), getElementsByTagName(...)->item(0))
 * must refresh a previously captured live getElementsByTagName NodeList (php-src nodelist.c).
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><a/><b/></r>');
$list = $d->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$d->documentElement->replaceChild(
    $d->createElement('a'),
    $d->getElementsByTagName('b')->item(0)
);
echo 'after=', $list->length, "\n";

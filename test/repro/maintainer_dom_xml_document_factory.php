<?php
declare(strict_types=1);
/**
 * Maintainer repro: Dom\XMLDocument::createFromString()/createEmpty() (#19581).
 *
 * Zend 8.4+: factories exist; createFromString yields documentElement->nodeName === 'root';
 * createEmpty() yields an instance with null documentElement.
 */
echo 'class=', class_exists('Dom\\XMLDocument') ? 'yes' : 'no', "\n";
echo 'createFromString=', method_exists(Dom\XMLDocument::class, 'createFromString') ? 'yes' : 'no', "\n";
echo 'createEmpty=', method_exists(Dom\XMLDocument::class, 'createEmpty') ? 'yes' : 'no', "\n";
$x = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root><a/></root>');
echo 'root=', $x->documentElement->nodeName, "\n";
$empty = Dom\XMLDocument::createEmpty();
echo 'empty_class=', $empty::class, "\n";
echo 'empty_root=', $empty->documentElement === null ? 'NULL' : $empty->documentElement->nodeName, "\n";

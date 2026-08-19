<?php
declare(strict_types=1);

/**
 * AOT DOMNamedNodeMap::$length / item() must not SIGSEGV (#32546).
 * php-src ext/dom/namednodemap.c php_dom_get_namednodemap_length / PHP_METHOD(DOMNamedNodeMap, item).
 */
$doc = new DOMDocument();
$doc->loadXML('<root id="x" class="y"/>');
$attrs = $doc->documentElement->attributes;
echo $attrs->length, '|', $attrs->item(0)->name, '|', $attrs->item(1)->name, "\n";

<?php

declare(strict_types=1);

/**
 * AOT: loadXML removeAttributeNode must update saveXML (#34579 leftover of #33577/#34257).
 * php-src ext/dom/element.c — php_dom_remove_attribute_node / xmlUnsetProp.
 */
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$el = $d->documentElement;
$el->removeAttributeNode($el->getAttributeNode('a'));
echo $el->hasAttribute('a') ? 'y' : 'n', ':', $d->saveXML();

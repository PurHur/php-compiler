<?php
/**
 * #27275 — AOT DOMXPath::query('//a') then item(1)->getAttribute must not segfault.
 */
$dom = new DOMDocument();
$dom->loadXML('<r><a id="1"/><a id="2"/></r>');
$xp = new DOMXPath($dom);
$n = $xp->query('//a');
echo $n->length, "\n";
echo $n->item(1)->getAttribute('id'), "\n";

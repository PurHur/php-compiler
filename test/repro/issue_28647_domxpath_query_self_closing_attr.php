<?php
/**
 * #28647 — AOT DOMXPath::query('//a[@id="1"]') on self-closing elements must
 * report length 1 (not 0). Closing-tag-only match missed <a id="1"/>.
 */
$d = new DOMDocument();
$d->loadXML('<r><a id="1"/></r>');
$x = new DOMXPath($d);
echo $x->query('//a[@id="1"]')->length, "\n";

<?php
declare(strict_types=1);

/**
 * AOT createElement+appendChild (no loadXML) must not SIGSEGV on saveXML,
 * and must keep root tagName + child text (php-src xmlAddChild / xmlDocDumpMemory).
 */
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a', '1');
$b = $doc->createElement('b', '2');
$root->appendChild($a);
$root->appendChild($b);
echo $root->tagName, '|', $root->firstChild->tagName, '|';
echo $doc->saveXML($root), '|';
echo $doc->saveXML();

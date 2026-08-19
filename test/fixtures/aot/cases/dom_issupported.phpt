--TEST--
AOT: isSupported must not abort as object::issupported (#32480, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
$root = $doc->documentElement;
echo (int) $root->isSupported('Core', '1.0'), '|';
echo (int) $root->isSupported('Core', '2.0'), '|';
echo (int) $root->isSupported('XML', '1.0'), '|';
echo (int) $root->isSupported('XML', '2.0'), '|';
echo (int) $root->isSupported('XML', '3.0'), '|';
echo (int) $root->isSupported('HTML', '1.0'), "\n";
--EXPECT--
1|0|1|1|0|0

--TEST--
AOT: hasFeature must match Zend dom_has_feature (#32491, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$impl = new DOMImplementation();
echo (int) $impl->hasFeature('Core', '1.0'), '|';
echo (int) $impl->hasFeature('Core', '2.0'), '|';
echo (int) $impl->hasFeature('XML', '1.0'), '|';
echo (int) $impl->hasFeature('XML', '2.0'), '|';
echo (int) $impl->hasFeature('XML', '3.0'), '|';
echo (int) $impl->hasFeature('HTML', '1.0'), "\n";
--EXPECT--
1|0|1|1|0|0

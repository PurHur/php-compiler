<?php
declare(strict_types=1);

/**
 * AOT DOMImplementation::hasFeature() must match Zend dom_has_feature (#32491).
 * php-src ext/dom/php_dom.c / implementation.c PHP_METHOD(DOMImplementation, hasFeature).
 */
$impl = new DOMImplementation();
echo (int) $impl->hasFeature('Core', '1.0'), '|';
echo (int) $impl->hasFeature('Core', '2.0'), '|';
echo (int) $impl->hasFeature('XML', '1.0'), '|';
echo (int) $impl->hasFeature('XML', '2.0'), '|';
echo (int) $impl->hasFeature('XML', '3.0'), '|';
echo (int) $impl->hasFeature('HTML', '1.0'), "\n";

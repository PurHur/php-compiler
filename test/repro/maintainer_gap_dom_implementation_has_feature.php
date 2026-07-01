<?php

declare(strict_types=1);

/** Issue #14354 — DOMImplementation::hasFeature('XML','1.0') (ext/dom/php_dom.c). */
$impl = new DOMImplementation();
echo (int) $impl->hasFeature('XML', '1.0'), "\n";
echo (int) $impl->hasFeature('XML', '2.0'), "\n";
echo (int) $impl->hasFeature('Core', '1.0'), "\n";
echo (int) $impl->hasFeature('HTML', '1.0'), "\n";

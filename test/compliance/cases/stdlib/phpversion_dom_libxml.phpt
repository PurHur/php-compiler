--TEST--
stdlib phpversion('dom') returns libxml DOM_API_VERSION (#15439, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion();
echo phpversion('dom') === '20031129' ? "dom_ok\n" : "dom_bad\n";
echo phpversion('pcre') === $core ? "pcre_ok\n" : "pcre_bad\n";
echo phpversion('nonexistent_xyz_15439') === false ? "unknown_ok\n" : "unknown_bad\n";
--EXPECT--
dom_ok
pcre_ok
unknown_ok

--TEST--
stdlib phpcredits(CREDITS_MODULES) lists loaded module authors only (#14295, #14799)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();
echo str_contains($out, 'Module Authors') ? "heading-ok\n" : "heading-missing\n";
echo (extension_loaded('curl') === str_contains($out, 'cURL')) ? "curl-row-ok\n" : "curl-row-bad\n";
echo (extension_loaded('mbstring') === str_contains($out, 'Multibyte String Functions')) ? "mbstring-row-ok\n" : "mbstring-row-bad\n";
$minLen = extension_loaded('curl') ? 1200 : 900;
echo strlen($out) >= $minLen ? "size-ok\n" : "size-bad\n";
--EXPECT--
heading-ok
curl-row-ok
mbstring-row-ok
size-ok

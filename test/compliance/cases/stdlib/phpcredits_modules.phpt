--TEST--
stdlib phpcredits(CREDITS_MODULES) lists php-src static module authors (#14295)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();
echo str_contains($out, 'Module Authors') ? "heading-ok\n" : "heading-missing\n";
echo str_contains($out, 'cURL') ? "curl-ok\n" : "curl-missing\n";
echo str_contains($out, 'Multibyte String Functions') ? "mbstring-ok\n" : "mbstring-missing\n";
echo strlen($out) >= 3900 ? "size-ok\n" : "size-bad\n";
--EXPECT--
heading-ok
curl-ok
mbstring-ok
size-ok

--TEST--
stdlib phpcredits(CREDITS_MODULES) full module authors table (#14295, #16338, ext/standard/credits.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();
echo str_contains($out, 'Module Authors') ? "heading-ok\n" : "heading-missing\n";
echo str_contains($out, 'cURL') ? "curl-row-ok\n" : "curl-row-missing\n";
echo str_contains($out, 'Perl Compatible Regexps') ? "pcre-row-ok\n" : "pcre-row-missing\n";
echo str_contains($out, 'OpenSSL') ? "openssl-row-ok\n" : "openssl-row-missing\n";
echo strlen($out) >= 3900 ? "size-ok\n" : "size-bad\n";
--EXPECT--
heading-ok
curl-row-ok
pcre-row-ok
openssl-row-ok
size-ok

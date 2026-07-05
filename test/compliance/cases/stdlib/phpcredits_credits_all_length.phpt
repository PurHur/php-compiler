--TEST--
phpcredits(CREDITS_ALL) full module table length matches php-src (#16367, ext/standard/credits.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();
echo str_contains($out, 'SAPI Modules') ? "sapi=1\n" : "sapi=0\n";
echo str_contains($out, 'CGI / FastCGI') ? "cgi=1\n" : "cgi=0\n";
echo str_contains($out, 'Module Authors') ? "modules=1\n" : "modules=0\n";
echo str_contains($out, 'Perl Compatible Regexps') ? "pcre=1\n" : "pcre=0\n";
$minLen = extension_loaded('curl') ? 6500 : 6000;
echo strlen($out) >= $minLen ? "size=1\n" : "size=0\n";
?>
--EXPECT--
sapi=1
cgi=1
modules=1
pcre=1
size=1

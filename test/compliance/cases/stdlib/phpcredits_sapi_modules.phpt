--TEST--
stdlib phpcredits(CREDITS_SAPI) emits SAPI Modules table (#14294, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_SAPI);
$out = ob_get_clean();
echo str_contains($out, 'SAPI Modules') ? "sapi-heading\n" : "sapi-heading-missing\n";
echo str_contains($out, 'CGI / FastCGI') ? "cgi-row\n" : "cgi-row-missing\n";
echo str_contains($out, 'Server API (SAPI) Abstraction Layer</h2>') ? "abstraction-bad\n" : "abstraction-absent\n";
echo str_contains($out, '<table>') ? "html-bad\n" : "html-absent\n";
?>
--EXPECT--
sapi-heading
cgi-row
abstraction-absent
html-absent

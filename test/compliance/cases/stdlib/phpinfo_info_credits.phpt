--TEST--
stdlib phpinfo(INFO_CREDITS) includes SAPI Modules and Module Authors (#14296)
--FILE--
<?php
ob_start();
phpinfo(INFO_CREDITS);
$out = ob_get_clean();
echo str_contains($out, 'SAPI Modules') ? "sapi-ok\n" : "sapi-missing\n";
echo str_contains($out, 'Module Authors') ? "modules-ok\n" : "modules-missing\n";
echo str_contains($out, 'PHP Authors') ? "authors-ok\n" : "authors-missing\n";
echo str_contains($out, 'cURL') ? "curl-row-ok\n" : "curl-row-missing\n";
echo strlen($out) >= 4000 ? "size-ok\n" : "size-bad\n";
?>
--EXPECT--
sapi-ok
modules-ok
authors-ok
curl-row-ok
size-ok

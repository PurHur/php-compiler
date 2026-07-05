--TEST--
stdlib phpinfo() CLI SAPI plain-text output (#16489, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_starts_with($out, '<!DOCTYPE') ? "html\n" : "text\n";
echo str_contains($out, 'PHP Version =>') ? "version-row\n" : "no-version-row\n";
echo str_contains($out, 'Server API => Command Line Interface') ? "sapi-label\n" : "no-sapi-label\n";
?>
--EXPECT--
text
version-row
sapi-label

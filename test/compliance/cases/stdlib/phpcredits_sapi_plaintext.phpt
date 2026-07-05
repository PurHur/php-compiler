--TEST--
stdlib phpcredits(CREDITS_SAPI) plain-text credits layout (#16345, ext/standard/credits.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_SAPI);
$out = ob_get_clean();
echo str_contains($out, 'PHP Credits') ? "header\n" : "header-missing\n";
echo str_contains($out, 'Contribution => Authors') ? "columns\n" : "columns-missing\n";
echo str_contains($out, '<table>') ? "html-bad\n" : "html-absent\n";
echo strlen($out) <= 800 ? "size-ok\n" : "size-bad\n";
?>
--EXPECT--
header
columns
html-absent
size-ok

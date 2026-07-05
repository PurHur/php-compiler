--TEST--
stdlib phpinfo() CLI SAPI emits plain-text rows not HTML (#16489, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpinfo(INFO_GENERAL);
$general = ob_get_clean();
echo str_starts_with($general, '<!DOCTYPE') ? "general-html\n" : "general-text\n";
echo str_contains($general, 'PHP Version =>') ? "general-version-row\n" : "general-version-missing\n";

ob_start();
phpinfo(INFO_CREDITS);
$credits = ob_get_clean();
echo str_starts_with($credits, '<!DOCTYPE') ? "credits-html\n" : "credits-text\n";
echo str_contains($credits, 'PHP Credits') ? "credits-heading\n" : "credits-heading-missing\n";
echo str_contains($credits, 'SAPI Modules') ? "credits-sapi\n" : "credits-sapi-missing\n";
?>
--EXPECT--
general-text
general-version-row
credits-text
credits-heading
credits-sapi

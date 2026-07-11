--TEST--
phpcredits(CREDITS_WEB|CREDITS_DOCS) — php-src credits.c section parity (#16347)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_WEB | CREDITS_DOCS);
$out = ob_get_clean();
echo str_contains($out, 'Websites and Infrastructure') ? "web-ok\n" : "web-missing\n";
echo str_contains($out, 'PHP Documentation') ? "docs-ok\n" : "docs-missing\n";
echo str_contains($out, 'Peter Cowburn') ? "editor-ok\n" : "editor-missing\n";
echo strlen($out) >= 400 ? "size-ok\n" : "size-bad\n";
?>
--EXPECT--
web-ok
docs-ok
editor-ok
size-ok

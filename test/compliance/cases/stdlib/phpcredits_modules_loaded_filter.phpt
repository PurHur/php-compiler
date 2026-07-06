--TEST--
stdlib phpcredits(CREDITS_MODULES) omits unloaded extension rows (#16338, ext/standard/credits.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();
$fail = 0;
if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
  ++$fail;
}
if (!extension_loaded('openssl') && str_contains($out, 'OpenSSL')) {
  ++$fail;
}
if (extension_loaded('json') && !str_contains($out, 'JSON')) {
  ++$fail;
}
echo 0 === $fail ? "filter-ok\n" : "filter-bad\n";
?>
--EXPECT--
filter-ok

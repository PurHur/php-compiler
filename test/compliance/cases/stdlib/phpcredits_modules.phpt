--TEST--
stdlib phpcredits(CREDITS_MODULES) module authors for loaded extensions (#14295, #16338, ext/standard/credits.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();
$fail = 0;
if (!str_contains($out, 'Module Authors')) {
    ++$fail;
}
if (!str_contains($out, 'Perl Compatible Regexps')) {
    ++$fail;
}
if (extension_loaded('curl') && !str_contains($out, 'cURL')) {
    ++$fail;
}
if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
    ++$fail;
}
if (extension_loaded('openssl') && !str_contains($out, 'OpenSSL')) {
    ++$fail;
}
if (!extension_loaded('openssl') && str_contains($out, 'OpenSSL')) {
    ++$fail;
}
$minLen = 1000;
if (strlen($out) < $minLen) {
    ++$fail;
}
echo 0 === $fail ? "modules-ok\n" : "modules-bad\n";
?>
--EXPECT--
modules-ok

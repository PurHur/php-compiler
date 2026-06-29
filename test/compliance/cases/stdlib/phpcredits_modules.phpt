--TEST--
stdlib phpcredits(CREDITS_MODULES) lists loaded module authors (#13618)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_MODULES);
$out = ob_get_clean();
echo str_contains($out, 'Module Authors') ? "heading-ok\n" : "heading-missing\n";
echo str_contains($out, 'Standard PHP Library') ? "standard-ok\n" : "standard-missing\n";
echo strlen($out) > 0 ? "nonempty-ok\n" : "nonempty-bad\n";
--EXPECT--
heading-ok
standard-ok
nonempty-ok

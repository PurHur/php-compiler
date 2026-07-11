--TEST--
stdlib phpcredits(CREDITS_QA) JIT emits QA section (#13618)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_QA);
$out = ob_get_clean();
echo str_contains($out, 'Quality Assurance') ? "qa-ok\n" : "qa-missing\n";
--EXPECT--
qa-ok

--TEST--
stdlib phpcredits(CREDITS_QA) emits QA team section (#13618, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_QA);
$out = ob_get_clean();
echo str_contains($out, 'Quality Assurance') ? "qa-ok\n" : "qa-missing\n";
echo strlen($out) > 0 ? "nonempty-ok\n" : "nonempty-bad\n";
--EXPECT--
qa-ok
nonempty-ok

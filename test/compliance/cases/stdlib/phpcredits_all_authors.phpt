--TEST--
phpcredits(CREDITS_ALL) includes PHP Authors section (ext/standard/credits.c; #14284)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Authors') ? "authors=1\n" : "authors=0\n";
echo str_contains($out, 'Zend Scripting Language Engine') ? "engine=1\n" : "engine=0\n";
echo strlen($out) >= 3500 ? "size=1\n" : "size=0\n";
?>
--EXPECT--
authors=1
engine=1
size=1

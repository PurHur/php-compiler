--TEST--
stdlib phpcredits(CREDITS_GENERAL) emits credits (#3359, #5304)
--FILE--
<?php
echo function_exists('phpcredits') ? "fn\n" : "no\n";
echo defined('CREDITS_GENERAL') ? "const\n" : "no\n";
ob_start();
phpcredits(CREDITS_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Credits') ? "credits ok\n" : "credits missing\n";
echo "credits_called\n";
--EXPECT--
fn
const
credits ok
credits_called

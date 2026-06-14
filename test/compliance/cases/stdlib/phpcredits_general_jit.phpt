--TEST--
stdlib phpcredits(CREDITS_GENERAL) JIT emits credits (#5304)
--FILE--
<?php
ob_start();
phpcredits(CREDITS_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Credits') ? "credits ok\n" : "credits missing\n";
--EXPECT--
credits ok

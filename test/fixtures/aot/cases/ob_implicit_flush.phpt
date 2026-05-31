--TEST--
AOT: ob_implicit_flush() registration and runtime hook (issue #3401)
--FILE--
<?php
echo function_exists('ob_implicit_flush') ? '1' : '0', "\n";
ob_implicit_flush(true);
ob_implicit_flush(false);
echo "ok\n";
--EXPECT--
1
ok

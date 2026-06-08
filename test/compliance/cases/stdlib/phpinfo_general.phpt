--TEST--
stdlib phpinfo(INFO_GENERAL) runtime introspection (#3359, #5304)
--FILE--
<?php
echo function_exists('phpinfo') ? "fn\n" : "no\n";
echo defined('INFO_GENERAL') ? "const\n" : "no\n";
ob_start();
$ok = phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Version') ? "phpinfo ok\n" : "phpinfo missing\n";
echo $ok ? "true\n" : "false\n";
--EXPECT--
fn
const
phpinfo ok
true

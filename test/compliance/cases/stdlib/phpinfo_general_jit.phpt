--TEST--
stdlib phpinfo(INFO_GENERAL) JIT — runtime introspection (#5304)
--FILE--
<?php
ob_start();
$ok = phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Version') ? "phpinfo ok\n" : "phpinfo missing\n";
echo $ok ? "true\n" : "false\n";
--EXPECT--
phpinfo ok
true

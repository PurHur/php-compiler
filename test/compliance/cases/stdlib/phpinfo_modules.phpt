--TEST--
stdlib phpinfo(INFO_MODULES) lists registered extensions (#5304)
--FILE--
<?php
ob_start();
phpinfo(INFO_MODULES);
$out = ob_get_clean();
echo str_contains($out, 'PHP Modules') ? "modules\n" : "no\n";
echo str_contains($out, 'standard') ? "standard\n" : "no\n";
--EXPECT--
modules
standard

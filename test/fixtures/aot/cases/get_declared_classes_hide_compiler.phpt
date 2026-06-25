--TEST--
AOT get_declared_classes() — hide PHPCompiler\ bootstrap classes (#11688)
--FILE--
<?php
declare(strict_types=1);
class UserDeclaredClass {}
$classes = get_declared_classes();
$phpc = 0;
foreach ($classes as $c) {
    if (str_starts_with($c, 'PHPCompiler\\')) {
        ++$phpc;
    }
}
echo 'phpc_count=', $phpc, "\n";
echo in_array('UserDeclaredClass', $classes, true) ? '1' : '0';
echo "\n";
--EXPECT--
phpc_count=0
1

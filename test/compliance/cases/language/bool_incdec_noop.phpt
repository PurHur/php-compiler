--TEST--
bool increment/decrement no-op preserves type (issue #7058, php-src-strict)
--FILE--
<?php
foreach (['++$t', '$t++', '++$f', '$f++', '--$t', '--$f'] as $op) {
    if ($op === '++$t') { $t=true; ++$t; echo "++true => ", get_debug_type($t), " ", var_export($t, true), "\n"; }
    if ($op === '$t++') { $t=true; $r=$t++; echo "\$t++ ret=", get_debug_type($r), " val=", get_debug_type($t), " ", var_export($t, true), "\n"; }
    if ($op === '++$f') { $f=false; ++$f; echo "++false => ", get_debug_type($f), " ", var_export($f, true), "\n"; }
    if ($op === '$f++') { $f=false; $r=$f++; echo "\$f++ ret=", get_debug_type($r), " val=", get_debug_type($f), " ", var_export($f, true), "\n"; }
    if ($op === '--$t') { $t=true; --$t; echo "--true => ", get_debug_type($t), " ", var_export($t, true), "\n"; }
    if ($op === '--$f') { $f=false; --$f; echo "--false => ", get_debug_type($f), " ", var_export($f, true), "\n"; }
}
--EXPECT--
++true => bool true
$t++ ret=bool val=bool true
++false => bool false
$f++ ret=bool val=bool false
--true => bool true
--false => bool false

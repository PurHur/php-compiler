--TEST--
rename() Zend stub names from/to/context + named from:/to: (#23348)
--FILE--
<?php
$rf = new ReflectionFunction('rename');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

$a = sys_get_temp_dir() . '/phpc_rename_named_23348_a_' . getmypid();
$b = sys_get_temp_dir() . '/phpc_rename_named_23348_b_' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
var_export(rename(from: $a, to: $b));
echo "\n";
echo is_file($b) ? file_get_contents($b) : 'missing', "\n";
try {
    rename(old_name: $a, new_name: $b);
    echo "legacy: accepted\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
@unlink($a);
@unlink($b);
--EXPECT--
from,to,context
true
x
legacy: Unknown named parameter $old_name

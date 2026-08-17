--TEST--
copy() Zend stub names from/to/context + named from:/to: (#23347)
--FILE--
<?php
$rf = new ReflectionFunction('copy');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

$a = sys_get_temp_dir() . '/phpc_copy_named_23347_a_' . getmypid();
$b = sys_get_temp_dir() . '/phpc_copy_named_23347_b_' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
var_export(copy(from: $a, to: $b));
echo "\n";
echo is_file($b) ? file_get_contents($b) : 'missing', "\n";
try {
    copy(source_file: $a, destination_file: $b);
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
legacy: Unknown named parameter $source_file

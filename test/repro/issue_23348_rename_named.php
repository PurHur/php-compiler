<?php
/**
 * #23348 — rename() Zend stub names from/to/context (not old_name/new_name)
 * php-src: ext/standard/file.stub.php — rename(string $from, string $to, $context = null): bool
 */
$rf = new ReflectionFunction('rename');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(' ', $names), "\n";

$a = sys_get_temp_dir() . '/phpc-ren-23348-a-' . getmypid();
$b = sys_get_temp_dir() . '/phpc-ren-23348-b-' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
try {
    var_export(rename(from: $a, to: $b));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo is_file($b) ? file_get_contents($b) : 'missing', "\n";
try {
    rename(old_name: $a, new_name: $b);
    echo "legacy: accepted\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
@unlink($a);
@unlink($b);

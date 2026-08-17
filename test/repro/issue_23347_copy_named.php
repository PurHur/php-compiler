<?php
/**
 * #23347 — copy() Zend stub names from/to/context (not source_file/destination_file)
 * php-src: ext/standard/file.stub.php — copy(string $from, string $to, $context = null): bool
 */
$rf = new ReflectionFunction('copy');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(' ', $names), "\n";

$a = sys_get_temp_dir() . '/phpc-copy-23347-a-' . getmypid();
$b = sys_get_temp_dir() . '/phpc-copy-23347-b-' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
try {
    var_export(copy(from: $a, to: $b));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo is_file($b) ? file_get_contents($b) : 'missing', "\n";
try {
    copy(source_file: $a, destination_file: $b);
    echo "legacy: accepted\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
@unlink($a);
@unlink($b);

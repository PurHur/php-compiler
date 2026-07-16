--TEST--
stdlib stream_select() on php://temp is selectable (#19688)
--FILE--
<?php
declare(strict_types=1);
$m = fopen('php://temp', 'r+');
fwrite($m, 'x');
rewind($m);
$read = [$m];
$write = null;
$except = null;
$n = stream_select($read, $write, $except, 0);
if (!is_int($n) || $n < 1) {
    fwrite(STDERR, 'expected ready>=1 for php://temp, got ');
    var_export($n);
    fwrite(STDERR, "\n");
    exit(1);
}
echo "temp_ok\n";

$mem = fopen('php://memory', 'r+');
fwrite($mem, 'x');
rewind($mem);
$read = [$mem];
$write = null;
$except = null;
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
try {
    stream_select($read, $write, $except, 0);
    echo "memory_unexpected\n";
} catch (ValueError $e) {
    echo "memory_value_error\n";
}
echo 'warnings=', count($warnings), "\n";
?>
--EXPECT--
temp_ok
memory_value_error
warnings=1

--TEST--
fgets(): null $length ≡ omit (php-src file.stub.php ?int $length = null, #29506)
--FILE--
<?php
$empty = fopen('php://memory', 'r');
echo 'empty_null=', var_export(fgets($empty, null), true), "\n";
fclose($empty);

$h = fopen('php://memory', 'r+');
fwrite($h, "hello\nworld");
rewind($h);
echo 'line_null=', var_export(fgets($h, null), true), "\n";
echo 'rest_omit=', var_export(fgets($h), true), "\n";
rewind($h);
echo 'line_pos=', var_export(fgets($h, 3), true), "\n";
foreach ([0, -1] as $length) {
    try {
        fgets($h, $length);
        echo "no error for $length\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
fclose($h);
?>
--EXPECT--
empty_null=false
line_null='hello
'
rest_omit='world'
line_pos='he'
ValueError:fgets(): Argument #2 ($length) must be greater than 0
ValueError:fgets(): Argument #2 ($length) must be greater than 0

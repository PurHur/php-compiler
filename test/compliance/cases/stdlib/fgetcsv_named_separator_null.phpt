--TEST--
stdlib fgetcsv() separator: named null — ValueError on $separator not $length (#12018, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);

try {
    fgetcsv($f, separator: null);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

fclose($f);
?>
--EXPECT--
fgetcsv(): Argument #3 ($separator) must be a single character

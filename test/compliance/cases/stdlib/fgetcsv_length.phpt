--TEST--
stdlib fgetcsv() length accepts numeric-string and float coercion (issue #4337)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, "a,b\n");
rewind($fp);
$row = fgetcsv($fp, '1024');
echo $row[0], '-', $row[1], "\n";
rewind($fp);
$row = fgetcsv($fp, 1024.7);
echo $row[0], '-', $row[1], "\n";
rewind($fp);
$row = fgetcsv($fp, '0');
echo $row[0], '-', $row[1], "\n";
rewind($fp);
$row = fgetcsv($fp, 0);
echo $row[0], '-', $row[1], "\n";
try {
    fgetcsv($fp, 'not-numeric');
    echo "no-te\n";
} catch (TypeError $e) {
    echo 'te:', $e->getMessage(), "\n";
}
try {
    fgetcsv($fp, -1);
    echo "no-ve\n";
} catch (ValueError $e) {
    echo 've:', $e->getMessage(), "\n";
}
fclose($fp);
--EXPECT--
a-b
a-b
a-b
a-b
te:fgetcsv(): Argument #2 ($length) must be of type ?int, string given
ve:fgetcsv(): Argument #2 ($length) must be between 0 and 9223372036854775806

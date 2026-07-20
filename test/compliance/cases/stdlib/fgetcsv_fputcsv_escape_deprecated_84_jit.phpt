--TEST--
stdlib fgetcsv()/fputcsv() omitted $escape E_DEPRECATED under PROFILE=8.4 JIT (#21179, file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (\E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";

        return true;
    }

    return false;
});
$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
$row = fgetcsv($f);
echo $row[0], ',', $row[1], "\n";
$g = fopen('php://memory', 'r+');
$n = fputcsv($g, ['a', 'b']);
echo 'put:', $n, "\n";
--EXPECT--
DEP:fgetcsv(): the $escape parameter must be provided as its default value will change
a,b
DEP:fputcsv(): the $escape parameter must be provided as its default value will change
put:4

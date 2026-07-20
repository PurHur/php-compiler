--TEST--
stdlib fgetcsv()/fputcsv() omitted $escape stays silent on reference profile (#21179)
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
a,b
put:4

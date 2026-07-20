<?php
/**
 * Issue #21179 — fgetcsv()/fputcsv() omitted $escape → E_DEPRECATED under PROFILE=8.4.
 * php-src: ext/standard/file.c
 */
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
$h = fopen('php://memory', 'r+');
fwrite($h, "x,y\n");
rewind($h);
$row2 = fgetcsv($h, null, ',', '"', '\\');
echo 'explicit_get:', $row2[0], ',', $row2[1], "\n";
$i = fopen('php://memory', 'r+');
$n2 = fputcsv($i, ['x', 'y'], ',', '"', '\\');
echo 'explicit_put:', $n2, "\n";

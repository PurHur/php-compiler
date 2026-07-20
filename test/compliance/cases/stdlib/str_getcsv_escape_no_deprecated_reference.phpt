--TEST--
stdlib str_getcsv() omitted $escape stays silent on reference profile (#21174)
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (\E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";

        return true;
    }

    return false;
});
$row = str_getcsv('a,b');
echo $row[0], ',', $row[1], "\n";
--EXPECT--
a,b

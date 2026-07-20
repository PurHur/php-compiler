--TEST--
stdlib str_getcsv() omitted $escape E_DEPRECATED under PROFILE=8.4 (#21174, file.c)
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
$row = str_getcsv('a,b');
echo $row[0], ',', $row[1], "\n";
$row2 = str_getcsv('a,b', ',', '"', '\\');
echo 'explicit:', $row2[0], ',', $row2[1], "\n";
$row3 = str_getcsv('x,y', escape: '\\');
echo 'named:', $row3[0], ',', $row3[1], "\n";
--EXPECT--
DEP:str_getcsv(): the $escape parameter must be provided as its default value will change
a,b
explicit:a,b
named:x,y

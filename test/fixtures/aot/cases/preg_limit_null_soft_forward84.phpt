--TEST--
AOT: preg_* null $limit soft-null on 8.4 (#21655)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
echo preg_replace('/\w/', 'x', 'ab', null) === 'ab' ? 'ok' : 'bad', "\n";
echo count(preg_split('//u', 'ab', null)) === 4 ? 'ok' : 'bad', "\n";
echo preg_filter('/\w/', 'x', 'ab', null) === null ? 'ok' : 'bad', "\n";
?>
--EXPECT--
DEP
ok
DEP
ok
DEP
ok

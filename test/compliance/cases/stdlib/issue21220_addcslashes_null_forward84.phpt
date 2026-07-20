--TEST--
stdlib addcslashes/stripcslashes null soft-null on 8.4 (#21220, ext/standard/string.c)
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
foreach ([
    'addcslashes' => static fn () => addcslashes(null, 'A..z'),
    'stripcslashes' => static fn () => stripcslashes(null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
DEP
addcslashes: uncaught ''
DEP
stripcslashes: uncaught ''

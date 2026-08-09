--TEST--
stdlib strtr() three-arg null $from/$to — DEP+coerce on 8.4 (#29308)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        $deps[] = $msg;
    }
    return true;
});
foreach ([
    'from_null' => static fn () => strtr('a', null, 'x'),
    'to_null' => static fn () => strtr('a', 'a', null),
    'both_null' => static fn () => strtr('a', null, null),
    'two_arg_null' => static fn () => strtr('a', null),
] as $label => $fn) {
    $deps = [];
    echo "== $label ==\n";
    try {
        $r = $fn();
        echo 'OK:'.var_export($r, true)."\n";
    } catch (Throwable $e) {
        echo get_class($e).':'.$e->getMessage()."\n";
    }
    foreach ($deps as $d) {
        echo 'DEP:'.$d."\n";
    }
}
--EXPECT--
== from_null ==
OK:'a'
DEP:strtr(): Passing null to parameter #2 ($from) of type array|string is deprecated
== to_null ==
OK:'a'
DEP:strtr(): Passing null to parameter #3 ($to) of type ?string is deprecated
== both_null ==
OK:'a'
DEP:strtr(): Passing null to parameter #2 ($from) of type array|string is deprecated
DEP:strtr(): Passing null to parameter #3 ($to) of type ?string is deprecated
== two_arg_null ==
TypeError:strtr(): Argument #2 ($from) must be of type array, string given

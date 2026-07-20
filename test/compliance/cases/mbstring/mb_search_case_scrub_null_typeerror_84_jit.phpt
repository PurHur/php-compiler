--TEST--
mb_strwidth/mb_convert_case/mb_scrub null on 8.4 profile JIT (#21061, #21313, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'mb_strwidth' => static fn () => mb_strwidth(null),
    'mb_convert_case' => static fn () => mb_convert_case(null, MB_CASE_UPPER),
    'mb_scrub' => static fn () => mb_scrub(null),
] as $name => $fn) {
    try {
        $r = $fn();
        if ('mb_convert_case' === $name) {
            echo "$name: OK ".var_export($r, true)."\n";
            continue;
        }
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
echo mb_strwidth('ab'), "\n";
echo mb_convert_case('ab', MB_CASE_UPPER), "\n";
echo mb_scrub("a\x80b"), "\n";
?>
--EXPECT--
mb_strwidth: mb_strwidth(): Argument #1 ($string) must be of type string, null given
mb_convert_case: OK ''
mb_scrub: mb_scrub(): Argument #1 ($string) must be of type string, null given
2
AB
a?b

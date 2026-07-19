--TEST--
mb_strwidth/mb_convert_case/mb_scrub null $string TypeError on 8.4 profile JIT (#21061, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'mb_strwidth' => static fn () => mb_strwidth(null),
    'mb_convert_case' => static fn () => mb_convert_case(null, MB_CASE_UPPER),
    'mb_scrub' => static fn () => mb_scrub(null),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
echo mb_strwidth('ab'), "\n";
echo mb_convert_case('ab', MB_CASE_UPPER), "\n";
echo mb_scrub("a\x80b"), "\n";
--EXPECT--
mb_strwidth: mb_strwidth(): Argument #1 ($string) must be of type string, null given
mb_convert_case: mb_convert_case(): Argument #1 ($string) must be of type string, null given
mb_scrub: mb_scrub(): Argument #1 ($string) must be of type string, null given
2
AB
a?b

--TEST--
forward_static_call_array() plain function name at global and class scope (#11667, ext/standard/basic_functions.c)
--FILE--
<?php
echo forward_static_call_array('strlen', ['abc']), "\n";
final class ForwardStaticBuiltinProbe {
    public static function run(): int {
        return forward_static_call_array('strlen', ['abc']);
    }
}
echo ForwardStaticBuiltinProbe::run(), "\n";
--EXPECT--
3
3

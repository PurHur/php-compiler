--TEST--
stdlib get_defined_functions() — withhold forward-profile phantom builtins on 8.4.0-dev (#16482, re-#16086)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || '8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
if (!PHPCompiler\CompilerVersion::supportsStrIncrement()) {
    die('skip str_increment not supported');
}
--FILE--
<?php
$internal = get_defined_functions()['internal'];
foreach (['str_increment', 'str_decrement', 'fpow', 'fmin', 'fmax', 'fadd', 'fsub', 'fmul'] as $fn) {
    echo $fn, '_fe=', function_exists($fn) ? '1' : '0', "\n";
    echo $fn, '_internal=', in_array($fn, $internal, true) ? '1' : '0', "\n";
}
--EXPECT--
str_increment_fe=1
str_increment_internal=1
str_decrement_fe=1
str_decrement_internal=1
fpow_fe=1
fpow_internal=1
fmin_fe=1
fmin_internal=1
fmax_fe=1
fmax_internal=1
fadd_fe=1
fadd_internal=1
fsub_fe=1
fsub_internal=1
fmul_fe=1
fmul_internal=1

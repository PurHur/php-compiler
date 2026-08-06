--TEST--
stdlib stream_context_set_options/set_params Reflection return true (#28239, basic_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsStreamContextSetOptions()) {
    die('skip stream_context_set_options needs PROFILE≥8.4');
}
?>
--FILE--
<?php
foreach (['stream_context_set_options', 'stream_context_set_params'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '?', PHP_EOL;
}
$c = stream_context_create();
echo 'opts=', stream_context_set_options($c, ['http' => ['method' => 'GET']]) ? '1' : '0', PHP_EOL;
echo 'params=', stream_context_set_params($c, []) ? '1' : '0', PHP_EOL;
?>
--EXPECT--
stream_context_set_options ret=true
stream_context_set_params ret=true
opts=1
params=1

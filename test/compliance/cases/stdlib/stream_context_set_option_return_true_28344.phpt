--TEST--
stdlib stream_context_set_option Reflection return true (#28344, streamsfuncs.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['stream_context_set_option', 'stream_context_set_options'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' => ', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', PHP_EOL;
}
$c = stream_context_create();
echo 'ok=', stream_context_set_option($c, 'http', 'method', 'GET') ? '1' : '0', PHP_EOL;
?>
--EXPECT--
stream_context_set_option => true
stream_context_set_options => true
ok=1

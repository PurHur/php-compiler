--TEST--
stdlib stream write buffer / context Zend stub named params (#23939)
--FILE--
<?php
foreach (['stream_set_write_buffer', 'stream_context_set_option', 'stream_context_set_params'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
try {
    @stream_set_write_buffer(stream: STDIN, size: 0);
    echo "write_buffer_named_ok\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage()."\n"
        : "write_buffer_named_ok\n";
}
$ctx = stream_context_create();
try {
    stream_context_set_params(context: $ctx, params: []);
    echo "set_params_named_ok\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage()."\n"
        : "set_params_named_ok\n";
}
try {
    stream_context_set_option(context: $ctx, wrapper_or_options: 'http', option_name: 'timeout', value: 1);
    echo "set_option_named_ok\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage()."\n"
        : "set_option_named_ok\n";
}
try {
    stream_set_write_buffer(fp: STDIN, buffer: 0);
    echo "fp accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    stream_context_set_params(context: $ctx, options: []);
    echo "options accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
stream_set_write_buffer:stream,size
stream_context_set_option:context,wrapper_or_options,option_name,value
stream_context_set_params:context,params
write_buffer_named_ok
set_params_named_ok
set_option_named_ok
Unknown named parameter $fp
Unknown named parameter $options

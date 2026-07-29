--TEST--
stream_wrapper_register / stream_filter_register Zend stub named params (#24488)
--FILE--
<?php
foreach (['stream_wrapper_register', 'stream_filter_register'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
try {
    stream_wrapper_register(protocol: 'phpc_test_wrap', class: 'stdClass');
    echo "wrapper_named_ok\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage() . "\n"
        : "wrapper_named_ok\n";
}
try {
    stream_filter_register(filter_name: 'phpc.test.filter', class: 'stdClass');
    echo "filter_named_ok\n";
} catch (Throwable $e) {
    echo (str_contains($e->getMessage(), 'Unknown named parameter'))
        ? $e->getMessage() . "\n"
        : "filter_named_ok\n";
}
try {
    stream_wrapper_register(protocol: 'phpc_bad', classname: 'stdClass');
    echo "classname accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
stream_wrapper_register:protocol,class,flags
stream_filter_register:filter_name,class
wrapper_named_ok
filter_named_ok
Unknown named parameter $classname

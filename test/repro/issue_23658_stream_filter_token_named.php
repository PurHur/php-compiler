<?php
/**
 * Repro #23658 — stream_get_meta_data / stream_set_blocking / filter_id / token_name
 * Zend stub named params (php-src basic_functions.stub.php, filter.stub.php, tokenizer.stub.php).
 */
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hi');
rewind($fp);

foreach (['stream_get_meta_data', 'stream_set_blocking', 'filter_id', 'token_name'] as $fn) {
    echo $fn, ':';
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo "\n";
}

try {
    echo 'meta=', count(stream_get_meta_data(stream: $fp)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    stream_get_meta_data(fp: $fp);
    echo "fp_should_fail\n";
} catch (Throwable $e) {
    echo "fp_rejected\n";
}

try {
    stream_set_blocking(stream: $fp, enable: true);
    echo "blocking_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    stream_set_blocking(socket: $fp, mode: true);
    echo "legacy_blocking_ok\n";
} catch (Throwable $e) {
    echo "legacy_blocking_rejected\n";
}

try {
    echo 'fid=', var_export(filter_id(name: 'email'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    filter_id(filtername: 'email');
    echo "filtername_ok\n";
} catch (Throwable $e) {
    echo "filtername_rejected\n";
}

try {
    echo 'tok=', token_name(id: T_IF), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    token_name(type: T_IF);
    echo "type_ok\n";
} catch (Throwable $e) {
    echo "type_rejected\n";
}

--TEST--
stream_get_meta_data/stream_set_blocking/filter_id/token_name Zend stub named params (#23658)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hi');
rewind($fp);

foreach (['stream_get_meta_data', 'stream_set_blocking', 'filter_id', 'token_name'] as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

echo 'meta=', count(stream_get_meta_data(stream: $fp)), "\n";
stream_set_blocking(stream: $fp, enable: true);
echo "blocking_ok\n";
echo 'fid=', var_export(filter_id(name: 'email'), true), "\n";
echo 'tok=', token_name(id: T_IF), "\n";

try {
    stream_get_meta_data(fp: $fp);
    echo "fp_ok\n";
} catch (Throwable $e) {
    echo "fp_rejected\n";
}
try {
    stream_set_blocking(socket: $fp, mode: true);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo "legacy_rejected\n";
}
try {
    filter_id(filtername: 'email');
    echo "filtername_ok\n";
} catch (Throwable $e) {
    echo "filtername_rejected\n";
}
try {
    token_name(type: T_IF);
    echo "type_ok\n";
} catch (Throwable $e) {
    echo "type_rejected\n";
}
?>
--EXPECT--
stream_get_meta_data:stream
stream_set_blocking:stream,enable
filter_id:name
token_name:id
meta=9
blocking_ok
fid=517
tok=T_IF
fp_rejected
legacy_rejected
filtername_rejected
type_rejected

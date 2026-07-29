--TEST--
JIT stream_get_meta_data/stream_set_blocking/filter_id/token_name Zend stub named params (#23658)
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

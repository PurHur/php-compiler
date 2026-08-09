--TEST--
stdlib stream_filter_append/prepend Reflection Zend names + no return (#28908)
--FILE--
<?php
foreach (['stream_filter_append', 'stream_filter_prepend'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = $p->getName();
    }
    echo $f, ' [', implode(', ', $ps), '] ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
}
$fp = fopen('php://memory', 'r+');
stream_filter_append(stream: $fp, filter_name: 'string.toupper');
echo "named_append_ok\n";
stream_filter_prepend(stream: $fp, filter_name: 'string.tolower', mode: 0);
echo "named_prepend_ok\n";
?>
--EXPECT--
stream_filter_append [stream, filter_name, mode, params] ret=(none)
stream_filter_prepend [stream, filter_name, mode, params] ret=(none)
named_append_ok
named_prepend_ok

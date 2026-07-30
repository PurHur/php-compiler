--TEST--
zlib_decode Reflection max_length=0; named max_length (#25132, re-#23655)
--FILE--
<?php
$data = zlib_encode('hello', ZLIB_ENCODING_DEFLATE);
$bits = [];
foreach ((new ReflectionFunction('zlib_decode'))->getParameters() as $p) {
    $bit = $p->getName() . ($p->isOptional() ? '=' : '');
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        $bit .= var_export($p->getDefaultValue(), true);
    }
    $bits[] = $bit;
}
echo implode(',', $bits), "\n";
echo var_export(zlib_decode(data: $data, max_length: 100), true), "\n";
try {
    zlib_decode(data: $data, max_decoded_len: 100);
    echo "legacy max_decoded_len accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy max_decoded_len rejected\n" : "legacy other\n";
}
--EXPECT--
data,max_length=0
'hello'
legacy max_decoded_len rejected

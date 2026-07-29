--TEST--
deflate_init/inflate_add Reflection + named args (VM, issue #24568)
--FILE--
<?php
foreach (['deflate_init', 'inflate_add', 'deflate_add', 'inflate_init'] as $f) {
    $rf = new ReflectionFunction($f);
    $n = [];
    foreach ($rf->getParameters() as $p) {
        $n[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ' req=', $rf->getNumberOfRequiredParameters(), ' [', implode(',', $n), ']', PHP_EOL;
}

$ctx = deflate_init(encoding: ZLIB_ENCODING_DEFLATE);
$enc = deflate_add(context: $ctx, data: 'hello', flush_mode: ZLIB_FINISH);
$ictx = inflate_init(encoding: ZLIB_ENCODING_DEFLATE);
echo inflate_add(context: $ictx, data: $enc, flush_mode: ZLIB_FINISH), PHP_EOL;

try {
    inflate_add(context: inflate_init(encoding: ZLIB_ENCODING_DEFLATE), encoded_data: 'x');
    echo "legacy encoded_data accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy encoded_data rejected\n" : "legacy encoded_data other\n";
}
--EXPECT--
deflate_init req=1 [encoding,options=]
inflate_add req=2 [context,data,flush_mode=]
deflate_add req=2 [context,data,flush_mode=]
inflate_init req=1 [encoding,options=]
hello
legacy encoded_data rejected

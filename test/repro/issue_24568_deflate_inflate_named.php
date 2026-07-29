<?php
/** Repro for #24568 — deflate_init/inflate_add Reflection arity/names + named round-trip. */
foreach (['deflate_init', 'inflate_add', 'deflate_add', 'inflate_init'] as $f) {
    $rf = new ReflectionFunction($f);
    $n = [];
    foreach ($rf->getParameters() as $p) {
        $n[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ' req=', $rf->getNumberOfRequiredParameters(), ' [', implode(',', $n), "]\n";
}

try {
    $ctx = deflate_init(encoding: ZLIB_ENCODING_DEFLATE);
    $enc = deflate_add(context: $ctx, data: 'hello', flush_mode: ZLIB_FINISH);
    $ictx = inflate_init(encoding: ZLIB_ENCODING_DEFLATE);
    echo inflate_add(context: $ictx, data: $enc, flush_mode: ZLIB_FINISH), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', str_starts_with($e->getMessage(), 'Unknown named parameter') ? 'named-rejected' : $e->getMessage(), "\n";
}

try {
    inflate_add(context: inflate_init(encoding: ZLIB_ENCODING_DEFLATE), encoded_data: 'x');
    echo "legacy_encoded_data=accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy_encoded_data=rejected\n" : "legacy_encoded_data=other\n";
}

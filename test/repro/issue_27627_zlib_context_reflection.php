<?php
/** Issue #27627 — deflate_init/inflate_init Reflection DeflateContext|false / InflateContext|false. */
foreach (['deflate_init', 'inflate_init'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', $p->getName(), ':', $t ? (string) $t : 'none', $p->isOptional() ? '=opt' : '', PHP_EOL;
    }
}
$ctx = deflate_init(ZLIB_ENCODING_DEFLATE);
echo 'type=', get_debug_type($ctx), PHP_EOL;
echo 'out_len=', strlen(deflate_add($ctx, 'hello', ZLIB_FINISH)), PHP_EOL;

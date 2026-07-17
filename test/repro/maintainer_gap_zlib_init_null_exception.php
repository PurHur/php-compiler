<?php

// Issue #19915 — inflate_init/deflate_init(null) must ValueError like Zend (ext/zlib/zlib.c).

$stderr = fopen('php://stderr', 'w');

foreach (['inflate_init', 'deflate_init'] as $func) {
    try {
        $func(null);
        fwrite($stderr, "FAIL:$func:no_exception\n");
        exit(1);
    } catch (\ValueError $e) {
        echo $func.': ValueError: '.$e->getMessage()."\n";
    } catch (\Throwable $e) {
        fwrite($stderr, 'FAIL:'.$func.':'.get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

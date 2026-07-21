<?php
/** Repro for #21878 — curl_setopt($ch, null, …) → ValueError (ext/curl/interface.c). */
$expected = 'curl_setopt(): Argument #2 ($option) is not a valid cURL option';
try {
    curl_setopt(curl_init(), null, 0);
    fwrite(STDERR, "fail:curl_setopt:no_throw\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, 'fail:curl_setopt:msg:'.$e->getMessage()."\n");
        exit(1);
    }
    echo "curl_setopt: ok\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'fail:curl_setopt:'.get_class($e).':'.$e->getMessage()."\n");
    exit(1);
}

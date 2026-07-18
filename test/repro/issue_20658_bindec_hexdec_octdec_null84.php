<?php
/** Repro for #20658 — bindec/hexdec/octdec(null) TypeError under PROFILE=8.4. */
foreach (['bindec', 'hexdec', 'octdec'] as $f) {
    try {
        $r = $f(null);
        echo $f, ' coerced:', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, '=', get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
echo 'ok:', bindec('1010'), ',', hexdec('a'), ',', octdec('12'), "\n";

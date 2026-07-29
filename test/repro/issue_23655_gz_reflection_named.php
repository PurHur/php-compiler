<?php
/** Repro for #23655 — gzread/gzwrite/gzclose/gzuncompress Reflection + Zend named args. */
foreach (['gzread', 'gzwrite', 'gzclose', 'gzuncompress'] as $fn) {
    $bits = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $fn, ':', implode(',', $bits), "\n";
}

$raw = gzcompress('hello-world');
try {
    echo 'named=', var_export(gzuncompress(data: $raw, max_length: 100), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    gzuncompress(data: $raw, max_decoded_len: 100);
    echo "legacy_max_decoded_len=accepted\n";
} catch (Throwable $e) {
    echo (str_starts_with($e->getMessage(), 'Unknown named parameter') ? 'legacy_max_decoded_len=rejected' : 'legacy_max_decoded_len=other'), "\n";
}

$path = sys_get_temp_dir() . '/phpc-issue-23655-' . getmypid() . '.gz';
$w = gzopen($path, 'w9');
try {
    $n = gzwrite(stream: $w, data: "line1\n");
    echo 'gzwrite_named=', var_export($n, true), "\n";
} catch (Throwable $e) {
    echo 'gzwrite_named=', get_class($e), ':', $e->getMessage(), "\n";
}
gzclose(stream: $w);

$h = gzopen($path, 'r');
try {
    echo 'gzread_named=', var_export(gzread(stream: $h, length: 20), true), "\n";
} catch (Throwable $e) {
    echo 'gzread_named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    gzread(zp: $h, length: 20);
    echo "legacy_zp=accepted\n";
} catch (Throwable $e) {
    echo (str_starts_with($e->getMessage(), 'Unknown named parameter') ? 'legacy_zp=rejected' : 'legacy_zp=other'), "\n";
}
try {
    gzwrite(string: $h, data: 'x');
    echo "legacy_string=accepted\n";
} catch (Throwable $e) {
    echo (str_starts_with($e->getMessage(), 'Unknown named parameter') ? 'legacy_string=rejected' : 'legacy_string=other'), "\n";
}
gzclose($h);
@unlink($path);

<?php
/** Repro for #23786 — named args + Reflection for html/hash/ob builtins. */
try {
    $t = get_html_translation_table(table: HTML_SPECIALCHARS);
    echo 'html=', var_export($t['"'] ?? null, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    hash_update(context: hash_init('sha1'), data: 'a');
    echo "hash_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(ob_get_status(full_status: false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$r = new ReflectionFunction('hash_update_stream');
echo 'stream_names=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
$tmp = tempnam(sys_get_temp_dir(), 'hus');
file_put_contents($tmp, 'hello');
$h = fopen($tmp, 'r');
$ctx = hash_init('sha1');
try {
    hash_update_stream(context: $ctx, stream: $h, length: 2);
    echo 'stream_digest=', hash_final($ctx), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
fclose($h);
@unlink($tmp);

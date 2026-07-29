<?php
/** Repro for #24563 — hash_update_file Reflection + Zend named stream_context. */
$r = new ReflectionFunction('hash_update_file');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'names=', implode(',', $names), ' arity=', $r->getNumberOfParameters(), "\n";
$tmp = tempnam(sys_get_temp_dir(), 'huf');
file_put_contents($tmp, 'abc');
$ctx = hash_init('md5');
try {
    hash_update_file(context: $ctx, filename: $tmp, stream_context: null);
    echo 'digest=', hash_final($ctx), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$ctx2 = hash_init('md5');
$sc = stream_context_create([]);
try {
    hash_update_file(context: $ctx2, filename: $tmp, stream_context: $sc);
    echo 'with_ctx=', hash_final($ctx2), "\n";
} catch (Throwable $e) {
    echo 'with_ctx=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    hash_update_file(context: hash_init('md5'), filename: $tmp, context_resource: null);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
@unlink($tmp);

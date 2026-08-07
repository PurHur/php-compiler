<?php
/**
 * #28318 — hash_file / hash_hmac_file Reflection return string|false (hash.stub.php).
 */
foreach (['hash_file', 'hash_hmac_file'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
$path = tempnam(sys_get_temp_dir(), 'h28318');
file_put_contents($path, 'x');
$h = hash_file('md5', $path);
echo 'hash_file_runtime=', (false === $h || is_string($h)) ? 'ok' : gettype($h), "\n";
$hm = hash_hmac_file('md5', $path, 'k');
echo 'hash_hmac_file_runtime=', (false === $hm || is_string($hm)) ? 'ok' : gettype($hm), "\n";
@unlink($path);

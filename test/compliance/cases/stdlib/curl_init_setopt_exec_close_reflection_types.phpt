--TEST--
stdlib curl_init/setopt/exec/close Reflection CurlHandle stubs (#26186, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
foreach (['curl_init', 'curl_setopt', 'curl_exec', 'curl_close'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $bit = $t . '$' . $p->getName();
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $bit .= '=' . var_export($p->getDefaultValue(), true);
        } elseif ($p->isOptional()) {
            $bit .= '=?';
        }
        $ps[] = $bit;
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
$h = curl_init();
echo 'runtime=', get_debug_type($h), "\n";
echo 'named=', curl_setopt(handle: $h, option: CURLOPT_RETURNTRANSFER, value: true) ? 'true' : 'false', "\n";
curl_close(handle: $h);
?>
--EXPECT--
curl_init(?string $url=NULL): CurlHandle|false
curl_setopt(CurlHandle $handle, int $option, mixed $value): bool
curl_exec(CurlHandle $handle): string|bool
curl_close(CurlHandle $handle): void
runtime=CurlHandle
named=true

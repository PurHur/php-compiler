--TEST--
stdlib curl_setopt_array/curl_multi_setopt Reflection handle types + bool return (#26107, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
foreach (['curl_setopt_array', 'curl_multi_setopt'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = $p->getName().':'.($p->hasType() ? (string) $p->getType() : '(none)');
    }
    echo $fn, '(', implode(', ', $ps), '): ', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
$mh = curl_multi_init();
echo 'setopt=', curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 10) ? 'true' : 'false', "\n";
curl_multi_close($mh);
?>
--EXPECT--
curl_setopt_array(handle:CurlHandle, options:array): bool
curl_multi_setopt(multi_handle:CurlMultiHandle, option:int, value:mixed): bool
setopt=true

--TEST--
curl_strerror/multi/share_strerror Reflection int $error_code → ?string (#27810, curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
foreach (['curl_strerror', 'curl_multi_strerror', 'curl_share_strerror'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ps[] = $t . '$' . $p->getName();
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
echo 'named_easy=', curl_strerror(error_code: CURLE_OK), "\n";
echo 'named_multi=', curl_multi_strerror(error_code: CURLM_OK), "\n";
echo 'named_share=', curl_share_strerror(error_code: 0), "\n";
?>
--EXPECT--
curl_strerror(int $error_code): ?string
curl_multi_strerror(int $error_code): ?string
curl_share_strerror(int $error_code): ?string
named_easy=No error
named_multi=No error
named_share=No error

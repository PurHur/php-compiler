<?php
/**
 * Repro #27810 — curl_strerror / curl_multi_strerror / curl_share_strerror Reflection
 * must match php-src curl.stub.php: (int $error_code): ?string + named error_code.
 *
 *   PHP_COMPILER_ENABLE_CURL=1 php bin/vm.php test/repro/issue_27810_curl_strerror_reflection.php
 */
foreach (['curl_strerror', 'curl_multi_strerror', 'curl_share_strerror'] as $fn) {
    if (!function_exists($fn)) {
        echo "$fn MISSING\n";
        continue;
    }
    $r = new ReflectionFunction($fn);
    $params = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        $params[] = $p->getName().':'.($t ? (string) $t : 'none');
    }
    $rt = $r->getReturnType();
    echo $fn.'|'.implode(',', $params).'|'.($rt ? (string) $rt : 'none')."\n";
}
try {
    echo 'named_multi='.var_export(curl_multi_strerror(error_code: CURLM_OK), true)."\n";
} catch (Throwable $e) {
    echo 'named_multi ERR='.$e->getMessage()."\n";
}
try {
    echo 'named_easy='.var_export(curl_strerror(error_code: CURLE_OK), true)."\n";
} catch (Throwable $e) {
    echo 'named_easy ERR='.$e->getMessage()."\n";
}
try {
    echo 'named_share='.var_export(curl_share_strerror(error_code: 0), true)."\n";
} catch (Throwable $e) {
    echo 'named_share ERR='.$e->getMessage()."\n";
}

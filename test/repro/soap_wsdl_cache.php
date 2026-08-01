<?php
declare(strict_types=1);

/**
 * Repro #26511 — SoapClient WSDL disk/memory cache (php-src php_sdl.c get_sdl).
 * Requires host php-soap.
 */
if (!extension_loaded('soap')) {
    fwrite(STDERR, "soap not advertised\n");
    exit(2);
}

$wsdlSrc = __DIR__ . '/../fixtures/soap/echo.wsdl';
$cacheDir = sys_get_temp_dir() . '/phpc_wsdl_cache_' . getmypid();
@mkdir($cacheDir, 0777, true);
$wsdlCopy = $cacheDir . '/echo.wsdl';
copy($wsdlSrc, $wsdlCopy);

ini_set('soap.wsdl_cache_enabled', '1');
ini_set('soap.wsdl_cache_dir', $cacheDir);
ini_set('soap.wsdl_cache_ttl', '86400');
ini_set('soap.wsdl_cache', (string) WSDL_CACHE_BOTH);

// First load — populate cache from file.
$c1 = new SoapClient($wsdlCopy, [
    'cache_wsdl' => WSDL_CACHE_BOTH,
    'location' => __DIR__ . '/../fixtures/soap/echo.response.xml',
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);
$fns1 = $c1->__getFunctions();
echo 'first_fns=', (int) (is_array($fns1) && count($fns1) > 0), "\n";

$diskHits = glob($cacheDir . '/wsdl-*.xml') ?: [];
echo 'disk_files=', count($diskHits), "\n";

// Remove source WSDL — cache must still serve.
unlink($wsdlCopy);

$c2 = new SoapClient($wsdlCopy, [
    'cache_wsdl' => WSDL_CACHE_BOTH,
    'location' => __DIR__ . '/../fixtures/soap/echo.response.xml',
    'uri' => 'http://example.com/echo',
]);
$fns2 = $c2->__getFunctions();
echo 'cached_fns=', (int) (is_array($fns2) && count($fns2) > 0), "\n";
echo 'fns_match=', (int) ($fns1 === $fns2), "\n";

// WSDL_CACHE_NONE must fail after unlink.
$failed = 0;
try {
    new SoapClient($wsdlCopy, ['cache_wsdl' => WSDL_CACHE_NONE]);
} catch (SoapFault $e) {
    $failed = 1;
}
echo 'none_fails=', $failed, "\n";

foreach ($diskHits as $f) {
    @unlink($f);
}
@rmdir($cacheDir);

--TEST--
curl Zend 8.2 stub constant families (#24132, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

$need = [
    'CURLAUTH_BASIC' => 1,
    'CURLAUTH_DIGEST' => 2,
    'CURLAUTH_NONE' => 0,
    'CURLAUTH_ANY' => -17,
    'CURLAUTH_AWS_SIGV4' => 128,
    'CURLALTSVC_H1' => 8,
    'CURLALTSVC_H2' => 16,
    'CURLALTSVC_H3' => 32,
    'CURLALTSVC_READONLYFILE' => 4,
    'CURLE_OK' => 0,
    'CURLE_COULDNT_CONNECT' => 7,
    'CURLE_COULDNT_RESOLVE_HOST' => 6,
    'CURLE_URL_MALFORMAT' => 3,
    'CURLPROTO_HTTP' => 1,
    'CURLPROTO_HTTPS' => 2,
    'CURLPROXY_HTTP' => 0,
    'CURLVERSION_NOW' => null, // value is libcurl-age dependent; only defined()
];
foreach ($need as $name => $want) {
    if (!defined($name)) {
        echo $name, "=UNDEF\n";
        continue;
    }
    if (null === $want) {
        echo $name, "=defined\n";
        continue;
    }
    $got = constant($name);
    echo $name, '=', $got === $want ? 'ok' : ("bad:{$got}"), "\n";
}

$curl = get_defined_constants(true)['curl'] ?? [];
$count = count($curl);
// Zend 8.2.32 advertises 649; we keep CURLOPT_ERRORBUFFER (#25814) → 650.
echo 'count_ok=', ($count >= 640 && $count <= 660) ? 'yes' : ("no:{$count}"), "\n";
echo 'errbuf=', defined('CURLOPT_ERRORBUFFER') ? 'yes' : 'no', "\n";
echo 'phantom_tcp_keepcnt=', defined('CURLOPT_TCP_KEEPCNT') ? 'PHANTOM' : 'undef', "\n";
?>
--EXPECT--
CURLAUTH_BASIC=ok
CURLAUTH_DIGEST=ok
CURLAUTH_NONE=ok
CURLAUTH_ANY=ok
CURLAUTH_AWS_SIGV4=ok
CURLALTSVC_H1=ok
CURLALTSVC_H2=ok
CURLALTSVC_H3=ok
CURLALTSVC_READONLYFILE=ok
CURLE_OK=ok
CURLE_COULDNT_CONNECT=ok
CURLE_COULDNT_RESOLVE_HOST=ok
CURLE_URL_MALFORMAT=ok
CURLPROTO_HTTP=ok
CURLPROTO_HTTPS=ok
CURLPROXY_HTTP=ok
CURLVERSION_NOW=defined
count_ok=yes
errbuf=yes
phantom_tcp_keepcnt=undef

--TEST--
curl CURL_HTTP_VERSION_* + 8.2 CURLOPT surface (#21336, #22837, ext/curl/curl.stub.php)
--FILE--
<?php
declare(strict_types=1);

$need = [
    'CURL_HTTP_VERSION_NONE' => 0,
    'CURL_HTTP_VERSION_1_0' => 1,
    'CURL_HTTP_VERSION_1_1' => 2,
    'CURL_HTTP_VERSION_2' => 3,
    'CURL_HTTP_VERSION_2_0' => 3,
    'CURL_HTTP_VERSION_2TLS' => 4,
    'CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE' => 5,
    'CURLOPT_FTP_RESPONSE_TIMEOUT' => 112,
    'CURLOPT_MAXFILESIZE' => 114,
    'CURLOPT_MAXFILESIZE_LARGE' => 30117,
    'CURLOPT_HSTS' => 10300,
    'CURLOPT_HSTS_CTRL' => 299,
    'CURLOPT_ALTSVC' => 10287,
    'CURLOPT_ALTSVC_CTRL' => 286,
    'CURLOPT_AWS_SIGV4' => 10305,
    'CURLOPT_CAINFO_BLOB' => 40309,
    'CURLOPT_HAPROXYPROTOCOL' => 274,
    'CURLINFO_REFERER' => 1048636,
    'CURLINFO_RETRY_AFTER' => 6291513,
];
foreach ($need as $name => $want) {
    if (!defined($name)) {
        echo $name, "=UNDEF\n";
        continue;
    }
    $got = constant($name);
    echo $name, '=', $got === $want ? 'ok' : ("bad:{$got}"), "\n";
}

// PHP 8.4-only — withheld on 8.4.0-dev reference / PROFILE=8.2 (#22837).
foreach ([
    'CURL_HTTP_VERSION_3',
    'CURL_HTTP_VERSION_3ONLY',
    'CURLINFO_POSTTRANSFER_TIME_T',
    'CURLOPT_TCP_KEEPCNT',
    'CURLOPT_SERVER_RESPONSE_TIMEOUT',
    'CURLOPT_PREREQFUNCTION',
    'CURLOPT_DEBUGFUNCTION',
] as $phantom) {
    echo $phantom, '=', defined($phantom) ? 'PHANTOM' : 'undef', "\n";
}

$ch = curl_init();
echo curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1) ? "setopt-http-version-ok\n" : "setopt-http-version-fail\n";
echo curl_setopt($ch, CURLOPT_FTP_RESPONSE_TIMEOUT, 60) ? "setopt-ftp-timeout-ok\n" : "setopt-ftp-timeout-fail\n";
curl_close($ch);
?>
--EXPECT--
CURL_HTTP_VERSION_NONE=ok
CURL_HTTP_VERSION_1_0=ok
CURL_HTTP_VERSION_1_1=ok
CURL_HTTP_VERSION_2=ok
CURL_HTTP_VERSION_2_0=ok
CURL_HTTP_VERSION_2TLS=ok
CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE=ok
CURLOPT_FTP_RESPONSE_TIMEOUT=ok
CURLOPT_MAXFILESIZE=ok
CURLOPT_MAXFILESIZE_LARGE=ok
CURLOPT_HSTS=ok
CURLOPT_HSTS_CTRL=ok
CURLOPT_ALTSVC=ok
CURLOPT_ALTSVC_CTRL=ok
CURLOPT_AWS_SIGV4=ok
CURLOPT_CAINFO_BLOB=ok
CURLOPT_HAPROXYPROTOCOL=ok
CURLINFO_REFERER=ok
CURLINFO_RETRY_AFTER=ok
CURL_HTTP_VERSION_3=undef
CURL_HTTP_VERSION_3ONLY=undef
CURLINFO_POSTTRANSFER_TIME_T=undef
CURLOPT_TCP_KEEPCNT=undef
CURLOPT_SERVER_RESPONSE_TIMEOUT=undef
CURLOPT_PREREQFUNCTION=undef
CURLOPT_DEBUGFUNCTION=undef
setopt-http-version-ok
setopt-ftp-timeout-ok

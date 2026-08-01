<?php
/**
 * Issue #24132 — curl Zend 8.2 stub constant families (php-src-strict).
 *
 * Expect CURLAUTH_, CURLALTSVC_, CURLE_ defined with Zend values; count near 650.
 */
declare(strict_types=1);

foreach ([
    'CURLAUTH_BASIC',
    'CURLAUTH_AWS_SIGV4',
    'CURLALTSVC_H1',
    'CURLE_COULDNT_CONNECT',
    'CURLE_OK',
    'CURLOPT_ALTSVC',
    'CURLVERSION_NOW',
] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'undef', "\n";
}
$curl = get_defined_constants(true)['curl'] ?? [];
echo 'count=', count($curl), "\n";

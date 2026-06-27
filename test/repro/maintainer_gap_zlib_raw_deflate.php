<?php

declare(strict_types=1);

/**
 * Issue #12706 — raw deflate bitstream must match Zend/libz for canonical payload.
 */

$plain = 'hello';
$zendRaw = zlib_encode($plain, ZLIB_ENCODING_RAW);
$vmRaw = gzdeflate($plain);

if (!is_string($vmRaw)) {
    echo "fail: gzdeflate returned false\n";
    exit(1);
}

$zendHex = bin2hex($zendRaw);
$vmHex = bin2hex($vmRaw);

if ($zendHex !== $vmHex) {
    echo "fail: hex mismatch zend={$zendHex} vm={$vmHex}\n";
    exit(1);
}

if ($plain !== gzinflate($vmRaw)) {
    echo "fail: gzinflate round-trip\n";
    exit(1);
}

echo "ok\n";

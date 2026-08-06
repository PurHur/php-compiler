<?php

/**
 * Issue #27883 — lz4 frame API + LZ4_* constants when advertised.
 */
echo 'ext=', extension_loaded('lz4') ? 'Y' : 'N', "\n";
foreach (['lz4_compress', 'lz4_uncompress', 'lz4_compress_frame', 'lz4_uncompress_frame'] as $f) {
    echo $f.'=', function_exists($f) ? 'Y' : 'N', "\n";
}
foreach (['LZ4_CLEVEL_MIN', 'LZ4_CLEVEL_MAX', 'LZ4_VERSION_NUMBER', 'LZ4_CHECKSUM_FRAME', 'LZ4_BLOCK_SIZE_64KB'] as $c) {
    echo $c.'=', defined($c) ? 'Y' : 'N', "\n";
}
echo 'LZ4_VERSION_TEXT=', defined('LZ4_VERSION_TEXT') ? 'Y' : 'N', "\n";
echo 'LZ4_CLEVEL_MIN_v=', defined('LZ4_CLEVEL_MIN') ? (string) LZ4_CLEVEL_MIN : 'N', "\n";
echo 'LZ4_CLEVEL_MAX_v=', defined('LZ4_CLEVEL_MAX') ? (string) LZ4_CLEVEL_MAX : 'N', "\n";

$plain = 'hello lz4 frame';
$frame = lz4_compress_frame($plain, 0, LZ4_BLOCK_SIZE_64KB, LZ4_CHECKSUM_FRAME);
echo 'frame_ok=', (false !== $frame && is_string($frame) && '' !== $frame) ? 'Y' : 'N', "\n";
$out = lz4_uncompress_frame($frame);
echo 'roundtrip=', ($out === $plain) ? 'Y' : 'N', "\n";

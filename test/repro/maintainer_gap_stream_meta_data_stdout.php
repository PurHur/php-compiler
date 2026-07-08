<?php

declare(strict_types=1);

$meta = stream_get_meta_data(STDOUT);
$expectedKeys = [
    'timed_out',
    'blocked',
    'eof',
    'wrapper_type',
    'stream_type',
    'mode',
    'unread_bytes',
    'seekable',
    'uri',
];
$keys = array_keys($meta);
echo 'seekable='.(($meta['seekable'] ?? true) ? 'true' : 'false')."\n";
echo 'keys='.implode(',', $keys)."\n";
echo 'keys_ok='.($keys === $expectedKeys ? '1' : '0')."\n";
echo 'seekable_ok='.((($meta['seekable'] ?? true) === false) ? '1' : '0')."\n";

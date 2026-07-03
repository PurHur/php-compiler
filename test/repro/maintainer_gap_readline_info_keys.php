<?php

declare(strict_types=1);

$info = readline_info();
$required = ['line_buffer', 'point', 'end', 'readline_name', 'attempted_completion_over', 'library_version'];
foreach ($required as $key) {
    if (!\array_key_exists($key, $info)) {
        echo "fail: missing key {$key}\n";
        exit(1);
    }
}

if (!\is_int($info['point']) || !\is_int($info['end']) || !\is_string($info['library_version'])) {
    echo "fail: wrong types point=", \gettype($info['point']), ' end=', \gettype($info['end']), ' library_version=', \gettype($info['library_version']), "\n";
    exit(1);
}

readline_info('line_buffer', 'abc');
if (3 !== readline_info('end') || 3 !== readline_info('point')) {
    echo 'fail: end/point after line_buffer set expected 3 got end=', readline_info('end'), ' point=', readline_info('point'), "\n";
    exit(1);
}

if (!\is_string(readline_info('library_version'))) {
    echo "fail: library_version getter\n";
    exit(1);
}

echo "ok\n";

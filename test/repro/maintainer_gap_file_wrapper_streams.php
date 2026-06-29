<?php
declare(strict_types=1);

$memory = file('php://memory');
if (!is_array($memory) || [] !== $memory) {
    echo "fail: file(php://memory) expected []\n";
    exit(1);
}

$data = file('data://text/plain,hi');
if (!is_array($data) || ['hi'] !== $data) {
    echo 'fail: file(data://) expected [hi], got ', var_export($data, true), "\n";
    exit(1);
}

$ignore = file("data://text/plain,a\nb", FILE_IGNORE_NEW_LINES);
if (!is_array($ignore) || ['a', 'b'] !== $ignore) {
    echo 'fail: FILE_IGNORE_NEW_LINES, got ', var_export($ignore, true), "\n";
    exit(1);
}

$skip = file("data://text/plain,a\n\nb", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($skip) || ['a', 'b'] !== $skip) {
    echo 'fail: FILE_SKIP_EMPTY_LINES, got ', var_export($skip, true), "\n";
    exit(1);
}

$missing = @file('/nonexistent/phpc_file_wrapper_'.getmypid().'.txt');
if (false !== $missing) {
    echo "fail: missing path should return false\n";
    exit(1);
}

echo "ok\n";

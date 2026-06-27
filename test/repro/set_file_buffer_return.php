<?php

/** Issue #12532 — set_file_buffer() returns prior write-buffer size (alias of stream_set_write_buffer). */
$f = fopen('php://memory', 'r+');
if (false === $f) {
    echo "FAIL: fopen\n";
    exit(1);
}
$ret = set_file_buffer($f, 0);
if (!is_int($ret)) {
    echo 'set_file_buffer_return: ', var_export($ret, true), "\n";
    echo "FAIL: expected int prior buffer size (-1 on php://memory), got boolean\n";
    exit(1);
}
if (-1 !== $ret) {
    echo 'set_file_buffer_return: ', var_export($ret, true), "\n";
    echo "FAIL: expected -1 on php://memory, got {$ret}\n";
    exit(1);
}
echo "ok\n";

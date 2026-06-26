<?php
// php-src-strict repro for #12153 — http_response_code(99) accepted on Zend, false on VM.
if (true === http_response_code(99)) {
    echo "ok\n";
} else {
    echo 'fail ret=', var_export(http_response_code(99), true), "\n";
}

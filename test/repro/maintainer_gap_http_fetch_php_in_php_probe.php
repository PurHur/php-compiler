<?php
// Repro for #8939 — file_get_contents(http://…) via VmHttpFetchPure (no duplicate libc socket FFI).
$body = @file_get_contents('http://example.com/');
echo is_string($body) && '' !== $body ? "ok\n" : "fail\n";

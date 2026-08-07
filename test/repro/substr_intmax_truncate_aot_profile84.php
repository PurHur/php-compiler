<?php
/**
 * AOT-safe repro #28556 — no set_error_handler (typed callbacks unsupported in JIT/AOT).
 * Warning check: run with 2>&1 and assert no "String is truncated" on the combined stream.
 */
echo substr('abc', 1, PHP_INT_MAX), "\n";
echo substr('hello', 0, 50), "\n";

<?php
declare(strict_types=1);

// Issue #4420 — FFI::cdef + puts via host libffi
var_dump(class_exists('FFI'));
$ffi = FFI::cdef('int puts(const char *s);');
$ffi->puts("hello from ffi\n");

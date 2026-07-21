<?php

// Issue #21724 — json_decode(JSON_INVALID_UTF8_IGNORE) must strip invalid UTF-8 in string literals.
$b = 'a' . chr(0x80) . 'b';
var_export(json_decode('"' . $b . '"', false, 512, JSON_INVALID_UTF8_IGNORE));
echo "\n";

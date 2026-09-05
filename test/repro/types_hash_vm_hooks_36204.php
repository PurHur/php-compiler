<?php
// is_null + hash still work after Types/Hash VmRuntimeSupport extract (#36204).
echo is_null(null) ? 'types-ok' : 'types-miss';
echo ' ';
$digest = hash('sha256', 'php-compiler-36204');
echo (64 === strlen($digest) && ctype_xdigit($digest)) ? 'hash-ok' : 'hash-miss';
echo "\n";

<?php
/**
 * #34800 — AOT base64_encode(binary) must match Zend (NestedJIT encodeArgv).
 */
$literal = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$png = base64_decode($literal);
$reenc = base64_encode($png);
echo 'enc_match=', var_export($reenc === $literal, true), "\n";
$via = file_get_contents('data://image/png;base64,'.$reenc);
echo 'roundtrip=', var_export($via === $png, true), "\n";
echo 'ascii=', var_export(base64_encode('hello') === 'aGVsbG8=', true), "\n";

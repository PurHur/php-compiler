<?php

declare(strict_types=1);

// AOT compile smoke for 2-arg curl_escape/unescape lowering (#20493).
// JIT encode path requires arity/string only; VM requires a real CurlHandle.
$h = 0;
echo curl_escape($h, 'a b'), "\n";
echo curl_unescape($h, 'a%20b'), "\n";

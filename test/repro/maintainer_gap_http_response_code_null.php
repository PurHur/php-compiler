<?php
// #18958 — http_response_code(null) returns false when unset, not TypeError (ext/standard/head.c).
$result = http_response_code(null);
echo 'result=' . var_export($result, true) . "\n";

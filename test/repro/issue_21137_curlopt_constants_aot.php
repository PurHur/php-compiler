<?php

declare(strict_types=1);

/**
 * Issue #21137 — CURLOPT_* / CURLINFO_* constants under AOT (no curl_init; JIT #6322).
 */
echo (string) CURLOPT_TIMEOUT, PHP_EOL;
echo (string) CURLOPT_CONNECTTIMEOUT, PHP_EOL;
echo (string) CURLOPT_FOLLOWLOCATION, PHP_EOL;
echo (string) CURLOPT_POSTFIELDS, PHP_EOL;
echo (string) CURLOPT_USERAGENT, PHP_EOL;
echo (string) CURLOPT_SSL_VERIFYPEER, PHP_EOL;
echo (string) CURLINFO_RESPONSE_CODE, PHP_EOL;

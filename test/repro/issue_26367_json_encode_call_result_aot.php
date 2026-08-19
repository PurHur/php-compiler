<?php

declare(strict_types=1);

/**
 * AOT: json_encode() on a runtime string from a call must not SIGSEGV (#26367).
 *
 * @see test/differential/cases/z26367_call_falsy_array_literal.php
 * php-src: ext/json/php_json.c — php_json_encode
 */

echo json_encode(strtoupper('x')), "\n";

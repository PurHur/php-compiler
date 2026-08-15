<?php

declare(strict_types=1);

/**
 * #31183 — AOT: literal malformed XML must emit Entity + snippet + caret, return false.
 */
error_reporting(E_ALL);
$result = simplexml_load_string('<');
var_dump($result);

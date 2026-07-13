<?php

declare(strict_types=1);

// Issue #18532 — sprintf('%d', []) must coerce like Zend (ext/standard/sprintf.c).
echo sprintf('%d', []), "\n";
echo @sprintf('%d', new stdClass()), "\n";
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";

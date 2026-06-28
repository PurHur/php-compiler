<?php

declare(strict_types=1);

/**
 * Issue #12964 — ArrayObject::exchangeArray() replaces backing storage.
 */

$ao = new ArrayObject([1]);
$old = $ao->exchangeArray([2]);
echo ($ao[0] === 2 && $old[0] === 1) ? "ok\n" : "fail\n";

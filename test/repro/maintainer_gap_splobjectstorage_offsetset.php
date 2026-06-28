<?php

declare(strict_types=1);

/**
 * Issue #12962 — SplObjectStorage array-style offsetSet/offsetGet.
 */

$storage = new SplObjectStorage();
$o = new stdClass();
$storage[$o] = 'meta';
echo ($storage[$o] === 'meta') ? "ok\n" : "fail: expected meta, got {$storage[$o]}\n";

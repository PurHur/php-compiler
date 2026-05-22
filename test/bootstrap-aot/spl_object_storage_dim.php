<?php

declare(strict_types=1);

/**
 * SplObjectStorage offset read/write for self-host Block::scope (issue #601).
 */

$storage = new SplObjectStorage();
$key = new stdClass();
$storage[$key] = 42;
echo $storage[$key], "\n";

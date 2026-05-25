<?php

declare(strict_types=1);

/**
 * SplObjectStorage::attach for Analyzer-style seen sets (issue #1998).
 */

$seen = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
if (!$seen->contains($a)) {
    $seen->attach($a);
}
if (!$seen->contains($b)) {
    $seen->attach($b);
}
echo $seen->count(), "\n";

<?php

declare(strict_types=1);

/**
 * Issue #13181 — SplObjectStorage::detach()/contains() (ext/spl/spl_observer.c).
 */

$storage = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$storage->attach($a);
$storage->attach($b);

if (!$storage->contains($a) || !$storage->contains($b)) {
    echo "fail: contains after attach\n";
    exit(1);
}
if (2 !== $storage->count()) {
    echo "fail: count after attach\n";
    exit(1);
}

$storage->detach($a);

if ($storage->contains($a)) {
    echo "fail: contains after detach\n";
    exit(1);
}
if (!$storage->contains($b)) {
    echo "fail: contains other after detach\n";
    exit(1);
}
if (1 !== $storage->count()) {
    echo "fail: count after detach\n";
    exit(1);
}

$seen = 0;
foreach ($storage as $obj) {
    if ($obj === $a) {
        echo "fail: foreach includes detached object\n";
        exit(1);
    }
    if ($obj === $b) {
        ++$seen;
    }
}
if (1 !== $seen) {
    echo "fail: foreach count after detach\n";
    exit(1);
}

echo "ok\n";

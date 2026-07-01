<?php

declare(strict_types=1);

/** Issue #14357 — SplObjectStorage::attach() non-object TypeError (ext/spl/spl_observer_storage.c). */
$s = new SplObjectStorage();
try {
    $s->attach(1);
    echo "no error\n";
    exit(1);
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if ($msg !== 'SplObjectStorage::attach(): Argument #1 ($object) must be of type object, int given') {
        echo 'fail:', $msg, "\n";
        exit(1);
    }
}
echo "ok\n";

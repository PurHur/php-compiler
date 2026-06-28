<?php

declare(strict_types=1);

/**
 * Issue #13142 — SplObjectStorage::getInfo()/setInfo()/getHash() (ext/spl/spl_observer.c).
 */

$storage = new SplObjectStorage();
$o1 = new stdClass();
$o2 = new stdClass();
$storage->attach($o1, 'info1');
$storage->attach($o2, 'info2');

$hash1 = $storage->getHash($o1);
if (!\is_string($hash1) || 32 !== \strlen($hash1)) {
    echo "fail: getHash format\n";
    exit(1);
}

$infos = [];
foreach ($storage as $obj) {
    $infos[] = $storage->getInfo();
    if ($obj === $o1) {
        $storage->setInfo('changed');
    }
}
if ($infos !== ['info1', 'info2']) {
    echo 'fail: getInfo during foreach expected info1,info2 got '.implode(',', array_map('strval', $infos))."\n";
    exit(1);
}
if ($storage[$o1] !== 'changed') {
    echo "fail: setInfo did not update offsetGet\n";
    exit(1);
}
if ($storage->getHash($o1) !== $hash1) {
    echo "fail: getHash not stable\n";
    exit(1);
}

echo "ok\n";

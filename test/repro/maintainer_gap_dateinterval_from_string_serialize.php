<?php

declare(strict_types=1);

$iv = DateInterval::createFromDateString('1 day');
$wire = serialize($iv);
$expected = 'O:12:"DateInterval":2:{s:11:"from_string";b:1;s:11:"date_string";s:5:"1 day";}';
if ($wire !== $expected) {
    fwrite(STDERR, "serialize wire mismatch\nexpected: {$expected}\ngot:      {$wire}\n");
    exit(1);
}

$round = unserialize($wire);
if (1 !== $round->d || 0 !== $round->h) {
    fwrite(STDERR, "roundtrip d/h wrong: d={$round->d} h={$round->h}\n");
    exit(1);
}

$zend = unserialize($expected);
if (1 !== $zend->d) {
    fwrite(STDERR, "Zend wire unserialize d={$zend->d}\n");
    exit(1);
}

echo "ok\n";

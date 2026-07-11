<?php

declare(strict_types=1);

$s = gc_status();
$legacy = ['runs', 'collected', 'threshold', 'roots'];
$php84 = ['running', 'protected', 'full', 'buffer_size'];

$hasLegacy = array_key_exists('runs', $s);
$hasPhp84 = array_key_exists('running', $s);

if ($hasLegacy && !$hasPhp84) {
  foreach ($legacy as $key) {
    if (!array_key_exists($key, $s)) {
      fwrite(STDERR, "missing legacy key: {$key}\n");
      exit(1);
    }
  }
  foreach ($php84 as $key) {
    if (array_key_exists($key, $s)) {
      fwrite(STDERR, "unexpected php84 key: {$key}\n");
      exit(1);
    }
  }
  echo "ok legacy\n";
  exit(0);
}

if ($hasPhp84 && !$hasLegacy) {
  foreach ($php84 as $key) {
    if (!array_key_exists($key, $s)) {
      fwrite(STDERR, "missing php84 key: {$key}\n");
      exit(1);
    }
  }
  echo "ok php84\n";
  exit(0);
}

fwrite(STDERR, "gc_status schema mismatch: keys=".implode(',', array_keys($s))."\n");
exit(1);

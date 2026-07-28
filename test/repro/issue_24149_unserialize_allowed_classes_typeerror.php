<?php

/**
 * Repro #24149 — unserialize() allowed_classes wrong type → TypeError (php-src var.c).
 */
foreach ([
  'string' => ['allowed_classes' => 'nope'],
  'object' => ['allowed_classes' => new stdClass()],
] as $n => $opts) {
  try {
    unserialize('O:8:"stdClass":0:{}', $opts);
    echo "$n OK\n";
  } catch (Throwable $e) {
    echo "$n ", get_class($e), ': ', $e->getMessage(), "\n";
  }
}
$ok = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => true]);
echo 'true ', get_class($ok), "\n";

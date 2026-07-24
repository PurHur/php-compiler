<?php
// Issue #22795 — createFromTimestamp is PHP 8.4+ only (not PROFILE=8.3).
echo 'immutable=', method_exists(DateTimeImmutable::class, 'createFromTimestamp') ? '1' : '0', PHP_EOL;
echo 'mutable=', method_exists(DateTime::class, 'createFromTimestamp') ? '1' : '0', PHP_EOL;
echo 'getMicrosecond=', method_exists(DateTimeImmutable::class, 'getMicrosecond') ? '1' : '0', PHP_EOL;

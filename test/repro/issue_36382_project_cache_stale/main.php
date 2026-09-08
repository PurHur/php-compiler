<?php
/**
 * #36382 — included unit change must invalidate multi-file AOT artifact cache.
 *
 * Repro for Runtime::standalone restoring aot.bin from entry-only computeKey while a
 * require()'d member changed (IncludeHelper / Composer graphs).
 */
require __DIR__ . '/lib/msg.php';
echo msg(), "\n";

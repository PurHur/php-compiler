<?php
/**
 * Repro for #28411 — MemoryUsage phantom absent under PROFILE≥8.4.
 * php-src never ships MemoryUsage; memory_get_* take bool $real_usage only.
 */
echo 'MemoryUsage=', enum_exists('MemoryUsage') ? 'Y' : 'N', "\n";
echo memory_get_usage(false) > 0 ? "usage_ok\n" : "usage_bad\n";

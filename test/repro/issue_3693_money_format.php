<?php
/**
 * Repro #3693 / #21481 — money_format() only on pre-8.0 profiles.
 * Under default / PROFILE=8.4 the symbol must be absent (php-src removed in 8.0).
 */
echo function_exists('money_format') ? "exists\n" : "missing\n";

<?php

/**
 * Repro #21755 — opcache_get_status() returns false when OPcache is off (Zend parity).
 */
var_export(opcache_get_status());
echo "\n";
var_export(opcache_get_status(false));
echo "\n";

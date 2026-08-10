<?php
/**
 * Repro #29575 — PHP 8.4+ exit(null)/die(null): E_DEPRECATED then exit 0.
 *
 * Zend 8.4: Deprecated exit(): Passing null to parameter #1 ($status) of type string|int;
 * exit code 0; no stdout.
 */
error_reporting(E_ALL);
exit(null);

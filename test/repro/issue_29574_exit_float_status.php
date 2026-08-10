<?php
/**
 * Repro #29574 — PHP 8.4+ exit(float)/die(float): int status + precision E_DEPRECATED.
 *
 * Zend 8.4: Deprecated Implicit conversion…; exit code truncated int; no stdout.
 * Pre-fix / pre-8.4 construct: stringifies float on stdout and exits 0.
 */
error_reporting(E_ALL);
exit(1.5);

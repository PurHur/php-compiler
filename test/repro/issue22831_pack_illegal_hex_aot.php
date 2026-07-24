<?php
/**
 * AOT-oriented repro #22831 — no set_error_handler (JitErrorHandler AOT gap).
 * Warnings go to STDERR; bytes on STDOUT.
 * php-src: ext/standard/pack.c
 */
error_reporting(E_ALL);
echo 'H=', bin2hex(pack('H', 'x')), "\n";
echo 'H*=', bin2hex(pack('H*', '0g1')), "\n";
echo 'h*=', bin2hex(pack('h*', 'gx')), "\n";

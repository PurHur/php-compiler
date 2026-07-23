<?php
// Issue #19950 / #22705 — typed class constants on forward profile (PHP_COMPILER_PROFILE=8.3+).
// Default 8.4.0-dev (phpversion 8.2.31) rejects like Zend 8.2 — see issue_22705_*.php.
class Config {
    const string VERSION = '1.0';
    const int MAX = 100;
}
echo Config::VERSION, "\n";
echo Config::MAX, "\n";

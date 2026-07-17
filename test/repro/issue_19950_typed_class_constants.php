<?php
// Issue #19950 — typed class constants on default dev profile (Zend/zend_compile.c).
class Config {
    const string VERSION = '1.0';
    const int MAX = 100;
}
echo Config::VERSION, "\n";
echo Config::MAX, "\n";

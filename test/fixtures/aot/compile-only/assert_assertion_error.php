<?php
// AOT compile-only: assert() AssertionError lowering (#3316).
ini_set('assert.exception', '1');
try {
    assert(false, 'fail');
} catch (AssertionError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

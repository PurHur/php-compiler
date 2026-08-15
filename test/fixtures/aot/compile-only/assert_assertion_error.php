<?php
// AOT compile-only: assert() AssertionError lowering (#3316).
// Runtime throw requires startup zend.assertions=1 (#31195); -l only needs emit.
ini_set('assert.exception', '1');
try {
    assert(false, 'fail');
} catch (AssertionError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

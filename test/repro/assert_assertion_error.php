<?php
// Needs zend.assertions≥1 at startup (cannot raise from -1 via ini_set; #24396 / #31195).
// Example: php -d zend.assertions=1 bin/vm.php test/repro/assert_assertion_error.php
ini_set('assert.exception', '1');
try {
    assert(false, 'fail');
    echo "no throw\n";
} catch (AssertionError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

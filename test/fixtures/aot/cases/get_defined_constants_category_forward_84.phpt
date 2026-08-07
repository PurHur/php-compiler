--TEST--
AOT: get_defined_constants() rejects $category on PROFILE=8.4 — php-src never shipped it (#28522, re-#17436)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    get_defined_constants(category: 'Core');
    echo "fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unknown named parameter $category

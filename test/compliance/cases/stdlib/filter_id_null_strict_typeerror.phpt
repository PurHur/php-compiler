--TEST--
Stdlib: filter_id(null) strict_types TypeError — Zend rejects null $name (#30309)
--FILE--
<?php
declare(strict_types=1);
try {
    filter_id(null);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
filter_id(): Argument #1 ($name) must be of type string, null given

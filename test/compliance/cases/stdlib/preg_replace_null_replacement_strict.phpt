--TEST--
stdlib preg_replace() null replacement TypeError under strict_types (#17871)
--FILE--
<?php
declare(strict_types=1);
try {
    preg_replace('/a/', null, 'abc');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
preg_replace(): Argument #2 ($replacement) must be of type array|string, null given

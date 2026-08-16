--TEST--
stdlib setlocale(null $category) TypeError under strict_types (#31487)
--FILE--
<?php
declare(strict_types=1);
try {
    setlocale(null, 'C');
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
setlocale(): Argument #1 ($category) must be of type int, null given

--TEST--
AOT htmlspecialchars/htmlentities(null $flags) TypeError under strict_types (#31212)
--FILE--
<?php
declare(strict_types=1);
try {
    htmlspecialchars('<a>', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    htmlentities('<a>', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
htmlspecialchars(): Argument #2 ($flags) must be of type int, null given
htmlentities(): Argument #2 ($flags) must be of type int, null given

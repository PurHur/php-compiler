--TEST--
AOT: non-numeric string << / >> TypeError matches Zend (#35308 leftover of #30138)
--FILE--
<?php
try {
    var_dump('a' << 1);
} catch (TypeError $e) {
    echo 'string <<: TypeError:', $e->getMessage(), "\n";
}
try {
    var_dump('a' >> 1);
} catch (TypeError $e) {
    echo 'string >>: TypeError:', $e->getMessage(), "\n";
}
echo 'numeric: ', '2' << 1, "\n";
?>
--EXPECT--
string <<: TypeError:Unsupported operand types: string << int
string >>: TypeError:Unsupported operand types: string >> int
numeric: 4

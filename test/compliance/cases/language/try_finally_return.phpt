--TEST--
Language: try/finally runs before return (#3082)
--FILE--
<?php
try {
    echo "t";
    return;
} finally {
    echo "f";
}
--EXPECT--
tf

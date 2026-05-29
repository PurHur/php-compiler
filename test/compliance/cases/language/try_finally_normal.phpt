--TEST--
Language: try/finally on normal completion (#3082)
--FILE--
<?php
try {
    echo "t";
} finally {
    echo "f";
}
--EXPECT--
tf

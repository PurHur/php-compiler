--TEST--
Language: AOT try/finally runs on normal completion (#24105)
--FILE--
<?php
try {
    echo "try ";
} finally {
    echo "fin";
}
--EXPECT--
try fin

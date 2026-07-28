--TEST--
Language: AOT try/catch/finally on throw (#24105)
--FILE--
<?php
try {
    throw new RuntimeException("x");
} catch (RuntimeException $e) {
    echo "caught ";
} finally {
    echo "fin";
}
--EXPECT--
caught fin

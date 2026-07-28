--TEST--
Language: AOT catch return runs finally (#24105)
--FILE--
<?php
function f() {
    try {
        throw new RuntimeException("x");
    } catch (RuntimeException $e) {
        echo "caught ";
        return 1;
    } finally {
        echo "fin";
    }
}
echo f();
--EXPECT--
caught fin1

--TEST--
Language: AOT catch return of string literal runs finally (#24105)
--FILE--
<?php
function f(): string {
    try {
        throw new RuntimeException("x");
    } catch (RuntimeException $e) {
        return "caught";
    } finally {
        echo "fin ";
    }
}
echo f(), "\n";
--EXPECT--
fin caught

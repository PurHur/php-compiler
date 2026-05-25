--TEST--
Language: finally runs after catch block (#57, #2084)
--FILE--
<?php
class E {}

try {
    throw new E();
} catch (E $e) {
    echo "catch\n";
} finally {
    echo "finally\n";
}
--EXPECT--
catch
finally

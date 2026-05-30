--TEST--
Language: bare throw; rethrows caught exception (#3508)
--FILE--
<?php
class Ex {}

try {
    try {
        throw new Ex();
    } catch (Ex $e) {
        throw;
    }
} catch (Ex $e) {
    echo "ok\n";
}
--EXPECT--
ok

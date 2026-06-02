--TEST--
Language: try/catch return message via MCJIT (issue #4246)
--FILE--
<?php
function f(): string {
    try {
        throw new Exception('x');
    } catch (Exception $e) {
        return $e->getMessage();
    }
}
echo f(), "\n";
--EXPECT--
x

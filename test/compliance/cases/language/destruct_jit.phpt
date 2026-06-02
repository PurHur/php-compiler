--TEST--
User __destruct() JIT compile smoke (issue #4096; MCJIT execute #98)
--FILE--
<?php
class D {
    public function __destruct() {
        echo "bye\n";
    }
}
new D();
echo "end\n";
--EXPECT--
end
bye

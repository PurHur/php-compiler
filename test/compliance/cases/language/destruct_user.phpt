--TEST--
User __destruct() after script output (issue #4013)
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

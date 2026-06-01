--TEST--
AOT: user __destruct() at process shutdown (issue #4013)
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

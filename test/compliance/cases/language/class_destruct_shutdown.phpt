--TEST--
User __destruct() at request shutdown (issue #3144)
--FILE--
<?php
class D {
    function __destruct() {
        echo "bye\n";
    }
}
new D();
--EXPECT--
bye

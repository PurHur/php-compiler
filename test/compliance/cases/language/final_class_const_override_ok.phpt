--TEST--
Language: non-final class constant may be overridden (#4455)
--FILE--
<?php
class Base {
    const X = 1;
}
class Child extends Base {
    const X = 2;
}
echo Child::X, "\n";
--EXPECT--
2

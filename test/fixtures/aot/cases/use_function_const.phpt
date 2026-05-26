--TEST--
AOT: use function and use const imports (issue #2325)
--FILE--
<?php
namespace N {
    const ANSWER = 42;
    function greet(): string {
        return 'hi';
    }
}
namespace User {
    use const N\ANSWER;
    use function N\greet;
    echo greet(), ' ', ANSWER, "\n";
}
--EXPECT--
hi 42

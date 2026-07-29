--TEST--
AOT: typed string parameter reaches function body correctly (#24723)
--FILE--
<?php
function greet(string $name): string {
    return "Hello, " . $name . "!";
}
echo greet("world"), "\n";
--EXPECT--
Hello, world!
--EXPECT_EXIT--
0

--TEST--
stdlib htmlspecialchars() / htmlentities() null encoding defaults to UTF-8 (#4296)
--FILE--
<?php
echo htmlspecialchars('a&b', ENT_QUOTES | ENT_SUBSTITUTE, null), "\n";
echo htmlentities('a&b', ENT_COMPAT, null), "\n";
try {
    htmlspecialchars('x', ENT_QUOTES, []);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
a&amp;b
a&amp;b
htmlspecialchars(): Argument #3 ($encoding) must be of type ?string, array given

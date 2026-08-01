--TEST--
Language: #[\Override] on property hook without parent hook fatals (#26328)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Solo {
    public string $x {
        #[\Override]
        get => "x";
    }
}
echo "should not reach here\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Solo::$x::get() has #[\Override] attribute, but no matching parent method exists

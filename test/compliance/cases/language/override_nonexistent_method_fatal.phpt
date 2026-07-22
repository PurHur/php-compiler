--TEST--
Language: #[\Override] on method not in parent — compile-time fatal (#21388, #22142)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Base {
    public function greet(): string { return "hello"; }
}
class Bad extends Base {
    #[\Override]
    public function nonExistent(): void {}
}
echo "should not reach here\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Bad::nonExistent() has #[\Override] attribute, but no matching parent method exists

--TEST--
AOT: strlen() — object operand TypeError (#10166)
--FILE--
<?php
declare(strict_types=1);

class C implements Stringable {
    public function __toString(): string { return 'hello'; }
}

strlen(new C());
--EXPECT--
--EXPECT_EXIT--
134

--TEST--
AOT: trait declaration with method (issue #2312)
--FILE--
<?php
trait Greets {
    public function greet(): string {
        return 'hello';
    }
}
echo trait_exists(Greets::class) ? '1' : '0', "\n";
--EXPECT--
1

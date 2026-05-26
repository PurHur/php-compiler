--TEST--
trait declaration with method body (issue #2312)
--FILE--
<?php
trait Greets {
    public function greet(): string {
        return 'hello';
    }
}
echo trait_exists(Greets::class) ? '1' : '0', "\n";
echo method_exists(Greets::class, 'greet') ? '1' : '0', "\n";
--EXPECT--
1
1

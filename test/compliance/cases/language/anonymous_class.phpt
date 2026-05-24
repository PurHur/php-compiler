--TEST--
language: anonymous class instantiation (issue #1233)
--FILE--
<?php
$o = new class {
    public function answer(): int {
        return 42;
    }
};
echo $o->answer(), "\n";
echo (new class {
    public function label(): string {
        return 'anon';
    }
})->label(), "\n";
--EXPECT--
42
anon

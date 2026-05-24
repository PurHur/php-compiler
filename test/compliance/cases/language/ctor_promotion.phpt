--TEST--
Constructor property promotion (issue #1359)
--FILE--
<?php
class C {
    public function __construct(private string $x = 'a') {}
    public function get(): string {
        return $this->x;
    }
}
echo (new C())->get(), "\n";
echo (new C('b'))->get(), "\n";
--EXPECT--
a
b

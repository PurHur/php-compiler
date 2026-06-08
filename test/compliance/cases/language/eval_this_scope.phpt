--TEST--
language eval() $this scope inside method (VM, issue #4410)
--FILE--
<?php
class C {
    public int $x = 1;
    public function run(): void {
        eval('$this->x = $this->x + 41;');
        echo $this->x, "\n";
    }
}
(new C())->run();
--EXPECT--
42

--TEST--
Language: explicit *(set) + matching set(string $v) on typed hooked property (#29672)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class PrivateSet {
    public private(set) string $x {
        set(string $v) { $this->x = strtoupper($v); }
    }
    public function setX(string $v): void { $this->x = $v; }
}
class ProtectedSet {
    public protected(set) string $x {
        set(string $v) { $this->x = strtoupper($v); }
    }
    public function setX(string $v): void { $this->x = $v; }
}
class PublicSet {
    public public(set) string $x {
        set(string $v) { $this->x = strtoupper($v); }
    }
    public function setX(string $v): void { $this->x = $v; }
}
$a = new PrivateSet();
$a->setX('ab');
echo $a->x, "\n";
$b = new ProtectedSet();
$b->setX('cd');
echo $b->x, "\n";
$c = new PublicSet();
$c->setX('ef');
echo $c->x, "\n";
--EXPECT--
AB
CD
EF

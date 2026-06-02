--TEST--
language: typed instance method + private visibility error (#4424)
--FILE--
<?php
class C {
    public function f(int $x): int {
        return $x + 1;
    }
}

$c = new C();
var_dump($c->f(41));

class D {
    private function secret(): int { return 7; }
    public function call(): int { return $this->secret(); }
}

$d = new D();
var_dump($d->call());

try {
    $d->secret();
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
int(42)
int(7)
Call to private method D::secret() from global scope

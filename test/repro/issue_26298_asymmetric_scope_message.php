<?php
// #26298 — asymmetric set/unset Errors use "from scope Class" (zend_execute.c)
class Alpha {
    public private(set) string $x = 'a';
}
class Beta extends Alpha {
    public function touch(): void
    {
        $this->x = 'b';
    }
}
$b = new Beta();
try {
    $b->touch();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $b->x = 'c';
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class Gamma {
    public protected(set) string $y = 'a';
}
class Delta {
    public function touch(Gamma $g): void
    {
        $g->y = 'b';
    }
}
try {
    (new Delta())->touch(new Gamma());
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class P {
    public private(set) string $z = 'a';
}
class C extends P {
    public function wipe(): void
    {
        unset($this->z);
    }
}
try {
    (new C())->wipe();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

<?php
/**
 * #29674 — promoted get-only hook ctor initializes backing (php-src-strict / PROFILE=8.4).
 */
class C {
    public function __construct(
        public string $name {
            get => strtoupper($this->name);
        }
    ) {}
}
$c = new C('hi');
echo $c->name, "\n";
$c->name = 'z';
echo $c->name, "\n";

class V {
    public function __construct(
        public string $name {
            get => 'CONST';
        }
    ) {}
}
try {
    new V('hi');
    echo "virtual_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

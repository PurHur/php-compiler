<?php
declare(strict_types=1);

class C {
    public function m(): string {
        return 'hi';
    }
}

$c = (new C())->m(...);
var_export($c());
echo "\n";

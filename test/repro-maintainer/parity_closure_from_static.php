<?php
declare(strict_types=1);

class C {
    public static function m(): string {
        return 'hi';
    }
}

$c = Closure::fromStatic(C::class . '::m');
var_export($c());
echo "\n";

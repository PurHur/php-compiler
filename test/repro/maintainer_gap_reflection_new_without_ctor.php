<?php
declare(strict_types=1);

final class NoCtor {
    private function __construct() {}
}

$r = new ReflectionClass(NoCtor::class);
$o = $r->newInstanceWithoutConstructor();
var_export($o instanceof NoCtor);
echo "\n";

<?php

declare(strict_types=1);

class BareMethodDeprecated {
    #[\Deprecated]
    public function f(): void {}
}

$rm = new ReflectionMethod(BareMethodDeprecated::class, 'f');
var_export($rm->isDeprecated());
echo "\n";

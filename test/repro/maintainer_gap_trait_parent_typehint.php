<?php
/**
 * Maintainer gap: parent typehint inside trait method.
 * Zend: parent resolves to the using class's parent (Base)
 * VM: type stays literal "parent" → TypeError even for Base instances
 */
error_reporting(E_ALL);

class BaseParentHint
{
}

trait TParentHint {
    public function take(parent $o): string
    {
        return get_class($o);
    }
}

class CParentHint extends BaseParentHint
{
    use TParentHint;
}

$c = new CParentHint();
$base = new BaseParentHint();

try {
    echo 'base: ' . $c->take($base) . "\n";
} catch (Throwable $e) {
    echo 'base: ' . get_class($e) . ':' . $e->getMessage() . "\n";
}

try {
    echo 'child: ' . $c->take($c) . "\n";
} catch (Throwable $e) {
    echo 'child: ' . get_class($e) . ':' . $e->getMessage() . "\n";
}

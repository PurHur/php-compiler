--TEST--
AOT: typed object formal — get_class/::class/instanceof match Zend (#24429)
--FILE--
<?php
class Ctx
{
    public int $x = 42;
}

class Target
{
    public function call(Ctx $context, string ...$args): void
    {
        echo 'class:', $context::class, "\n";
        echo 'get_class:', get_class($context), "\n";
        echo 'instanceof:', ($context instanceof Ctx) ? 'y' : 'n', "\n";
        echo 'prop:', $context->x, "\n";
        echo 'count:', count($args), "\n";
    }
}

(new Target())->call(new Ctx(), 'a', 'b');
--EXPECT--
class:Ctx
get_class:Ctx
instanceof:y
prop:42
count:2

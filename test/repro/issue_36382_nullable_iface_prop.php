<?php
// #36382 — AOT: assign null to ?Interface / ?Class typed property (Slim CallableResolver).
// Typed `?I $v = null` params lower as TYPE_OBJECT + null pointer; class_id load SIGSEGV'd.
interface CI36382
{
}
class Impl36382 implements CI36382
{
}
class CR36382
{
    private ?CI36382 $container;

    public function __construct(?CI36382 $container = null)
    {
        $this->container = $container;
    }

    public function has(): bool
    {
        return null !== $this->container;
    }
}
class ConcreteProp36382
{
    private ?Impl36382 $obj;

    public function __construct(?Impl36382 $obj = null)
    {
        $this->obj = $obj;
    }

    public function has(): bool
    {
        return null !== $this->obj;
    }
}
echo "null_iface\n";
$a = new CR36382(null);
echo $a->has() ? "has\n" : "empty\n";
echo "default_iface\n";
$b = new CR36382();
echo $b->has() ? "has\n" : "empty\n";
echo "obj_iface\n";
$c = new CR36382(new Impl36382());
echo $c->has() ? "has\n" : "empty\n";
echo "null_class\n";
$d = new ConcreteProp36382(null);
echo $d->has() ? "has\n" : "empty\n";
echo "OK\n";

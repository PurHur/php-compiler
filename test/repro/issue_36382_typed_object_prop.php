<?php
declare(strict_types=1);

/**
 * #36382 — `public object $o` must accept any object (zend IS_OBJECT), not
 * instanceof a class named "object". Blocks Slim AppFactory Runner($this).
 */

final class A36382Obj
{
}

final class Holder36382Obj
{
    public object $o;

    public function set(object $o): void
    {
        $this->o = $o;
    }
}

final class Child36382This
{
    public object $parent;

    public function __construct(object $parent)
    {
        $this->parent = $parent;
    }
}

final class Parent36382This
{
    public Child36382This $c;

    public function __construct()
    {
        $this->c = new Child36382This($this);
    }
}

$h = new Holder36382Obj();
$h->set(new A36382Obj());
echo isset($h->o) ? 'isset1' : 'unset1', "\n";
echo get_class($h->o), "\n";

$p = new Parent36382This();
echo get_class($p->c->parent), "\n";
echo 'ok', "\n";

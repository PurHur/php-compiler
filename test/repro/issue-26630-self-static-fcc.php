<?php
/**
 * Repro #26630 — self::/static:: instance method FCC binds like parent:: (zend_ast.c).
 */
class P
{
    public function f(): string
    {
        return 'P';
    }

    public function viaSelfFromP(): string
    {
        $c = self::f(...);

        return $c();
    }

    public function viaStaticFromP(): string
    {
        $c = static::f(...);

        return $c();
    }
}
class C extends P
{
    public function f(): string
    {
        return 'C';
    }

    public function viaSelf(): string
    {
        $c = self::f(...);

        return $c();
    }

    public function viaParent(): string
    {
        $c = parent::f(...);

        return $c();
    }

    public function viaStatic(): string
    {
        $c = static::f(...);

        return $c();
    }
}
$o = new C();
echo 'self='.$o->viaSelf()."\n";
echo 'parent='.$o->viaParent()."\n";
echo 'static='.$o->viaStatic()."\n";
echo 'Pself='.$o->viaSelfFromP()."\n";
echo 'Pstatic='.$o->viaStaticFromP()."\n";

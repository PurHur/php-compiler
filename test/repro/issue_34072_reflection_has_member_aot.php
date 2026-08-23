<?php
/**
 * #34072 — ReflectionClass hasMethod / hasProperty / hasConstant under thin AOT.
 *
 * Expect (Zend):
 *   M=1,0
 *   P=1,0
 *   C=1,0
 */
class C
{
    public $x;
    const K = 1;
    public function f(): void
    {
    }
}

$r = new ReflectionClass(C::class);
echo 'M=', ($r->hasMethod('f') ? '1' : '0'), ',', ($r->hasMethod('missing') ? '1' : '0'), "\n";
echo 'P=', ($r->hasProperty('x') ? '1' : '0'), ',', ($r->hasProperty('missing') ? '1' : '0'), "\n";
echo 'C=', ($r->hasConstant('K') ? '1' : '0'), ',', ($r->hasConstant('missing') ? '1' : '0'), "\n";

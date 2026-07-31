<?php
/**
 * #25963 — ReflectionClassConstant::getAttributes() on attributed class constants.
 *
 * Expected (Zend): X has A + B(3); Y empty; filtered A::class / IS_INSTANCEOF work.
 */
#[Attribute]
class A {}
#[Attribute]
class B
{
    public function __construct(public int $x) {}
}

class C
{
    #[A]
    #[B(3)]
    public const X = 1;

    public const Y = 2;
}

$rx = new ReflectionClassConstant(C::class, 'X');
echo 'Xcount=', count($rx->getAttributes()), "\n";
foreach ($rx->getAttributes() as $a) {
    echo $a->getName(), ':';
    var_export($a->getArguments());
    echo "\n";
}
$inst = $rx->getAttributes(B::class)[0]->newInstance();
echo 'Bnew=', $inst->x, "\n";
echo 'Afilter=', count($rx->getAttributes(A::class)), "\n";
echo 'IS_INSTANCEOF=', count($rx->getAttributes(A::class, ReflectionAttribute::IS_INSTANCEOF)), "\n";

$ry = new ReflectionClassConstant(C::class, 'Y');
echo 'Ycount=', count($ry->getAttributes()), "\n";

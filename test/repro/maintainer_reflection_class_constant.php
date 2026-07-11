<?php

declare(strict_types=1);

#[Attribute]
class A
{
    public function __construct(public string $x)
    {
    }
}

class C
{
    #[A('k')]
    private const K = 123;

    protected const P = 456;

    public const U = 789;
}

$rc = new ReflectionClassConstant(C::class, 'K');

echo $rc->getName(), "\n";
echo ($rc->isPrivate() ? 'private' : 'not-private'), "\n";
echo $rc->getValue(), "\n";

$attrs = $rc->getAttributes(A::class);
echo count($attrs), "\n";
if ($attrs) {
    $inst = $attrs[0]->newInstance();
    echo get_class($inst), ':', $inst->x, "\n";
}

$pub = new ReflectionClassConstant(C::class, 'U');
$prot = new ReflectionClassConstant(C::class, 'P');
echo ($pub->isPublic() ? 'public' : 'not-public'), "\n";
echo ($prot->isProtected() ? 'protected' : 'not-protected'), "\n";
echo ($pub->isPrivate() ? 'private' : 'not-private'), "\n";

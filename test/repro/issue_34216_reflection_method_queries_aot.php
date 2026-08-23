<?php
/**
 * #34216 — ReflectionMethod isPublic/isStatic/getNumberOfParameters under thin AOT.
 *
 * Expect (Zend):
 *   pub=1
 *   static=0
 *   n=2
 *   req=1
 *   s_static=1
 */
class T
{
    public function m(int $a, string $b = 'x'): void
    {
    }

    public static function s(): void
    {
    }
}

$rm = new ReflectionMethod(T::class, 'm');
echo 'pub=', ($rm->isPublic() ? '1' : '0'), "\n";
echo 'static=', ($rm->isStatic() ? '1' : '0'), "\n";
echo 'n=', $rm->getNumberOfParameters(), "\n";
echo 'req=', $rm->getNumberOfRequiredParameters(), "\n";

$rs = new ReflectionMethod(T::class, 's');
echo 's_static=', ($rs->isStatic() ? '1' : '0'), "\n";

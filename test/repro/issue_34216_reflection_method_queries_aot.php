<?php
/**
 * #34216 — ReflectionMethod isPublic/isStatic/getNumberOfParameters under thin AOT.
 *
 * Expect (Zend):
 *   pub=1
 *   static=0
 *   n=2
 *   req=1
 *   spub=1
 *   sstatic=1
 *   sn=2
 *   sreq=1
 */
class T
{
    public function m($a, $b = 1): void
    {
    }

    public static function s($a, $b = 1): void
    {
    }
}

$r = new ReflectionMethod(T::class, 'm');
echo 'pub=', ($r->isPublic() ? '1' : '0'), "\n";
echo 'static=', ($r->isStatic() ? '1' : '0'), "\n";
echo 'n=', $r->getNumberOfParameters(), "\n";
echo 'req=', $r->getNumberOfRequiredParameters(), "\n";

$s = new ReflectionMethod(T::class, 's');
echo 'spub=', ($s->isPublic() ? '1' : '0'), "\n";
echo 'sstatic=', ($s->isStatic() ? '1' : '0'), "\n";
echo 'sn=', $s->getNumberOfParameters(), "\n";
echo 'sreq=', $s->getNumberOfRequiredParameters(), "\n";

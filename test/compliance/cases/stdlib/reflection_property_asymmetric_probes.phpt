--TEST--
ReflectionProperty asymmetric visibility probes (issue #6977, #28185, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public (private(set)) string $p = 'x';
    public (protected(set)) int $q = 1;
    public int $plain = 0;
}

$p = new ReflectionProperty(C::class, 'p');
echo 'p_privSet=', $p->isPrivateSet() ? '1' : '0', "\n";
echo 'p_protSet=', $p->isProtectedSet() ? '1' : '0', "\n";
echo 'p_privGet=', $p->isPrivateGet() ? '1' : '0', "\n";
echo 'p_pubGet=', $p->isPublicGet() ? '1' : '0', "\n";

$q = new ReflectionProperty(C::class, 'q');
echo 'q_protSet=', $q->isProtectedSet() ? '1' : '0', "\n";
echo 'q_privSet=', $q->isPrivateSet() ? '1' : '0', "\n";

$plain = new ReflectionProperty(C::class, 'plain');
echo 'plain_privSet=', $plain->isPrivateSet() ? '1' : '0', "\n";
echo 'plain_protSet=', $plain->isProtectedSet() ? '1' : '0', "\n";

class S {
    public (protected(set)) static string $sp = 'y';
}
$sp = new ReflectionProperty(S::class, 'sp');
echo 'sp_protSet=', $sp->isProtectedSet() ? '1' : '0', "\n";
echo 'sp_pubGet=', $sp->isPublicGet() ? '1' : '0', "\n";
--EXPECT--
p_privSet=1
p_protSet=0
p_privGet=0
p_pubGet=1
q_protSet=1
q_privSet=0
plain_privSet=0
plain_protSet=0
sp_protSet=1
sp_pubGet=1

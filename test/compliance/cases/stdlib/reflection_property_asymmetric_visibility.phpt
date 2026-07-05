--TEST--
ReflectionProperty::getAsymmetricVisibility() — instance + static (#5060, #6772, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public (private(set)) string $name = 'x';
    public (protected(set)) int $q = 1;
    public int $plain = 0;
}

class S {
    private(set) static int $sx = 1;
    protected(set) static string $sp = 'y';
}

$r = new ReflectionProperty(C::class, 'name');
$q = new ReflectionProperty(C::class, 'q');
$plain = new ReflectionProperty(C::class, 'plain');
$rs = new ReflectionProperty(S::class, 'sx');
$rsp = new ReflectionProperty(S::class, 'sp');

$asym = $r->getAsymmetricVisibility();
echo 'name_get=', $asym['get'], ' name_set=', $asym['set'], "\n";

$asymQ = $q->getAsymmetricVisibility();
echo 'q_get=', $asymQ['get'], ' q_set=', $asymQ['set'], "\n";

echo 'plain_null=', var_export($plain->getAsymmetricVisibility(), true), "\n";

$asymStatic = $rs->getAsymmetricVisibility();
echo 'sx_get=', $asymStatic['get'], ' sx_set=', $asymStatic['set'], "\n";

$asymSp = $rsp->getAsymmetricVisibility();
echo 'sp_get=', $asymSp['get'], ' sp_set=', $asymSp['set'], "\n";
--EXPECT--
name_get=256 name_set=1024
q_get=256 q_set=512
plain_null=NULL
sx_get=256 sx_set=1024
sp_get=256 sp_set=512

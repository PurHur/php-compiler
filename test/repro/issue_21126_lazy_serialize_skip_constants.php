<?php

// Issue #21126 — ReflectionClass::SKIP_* + default serialize initializes lazy ghost.
echo defined('ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE') ? 'Y' : 'N', "\n";
echo defined('ReflectionClass::SKIP_DESTRUCTOR') ? 'Y' : 'N', "\n";
echo ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE, "\n";
echo ReflectionClass::SKIP_DESTRUCTOR, "\n";

class A
{
    public int $x;

    public function __construct()
    {
        $this->x = 7;
        echo "ctor\n";
    }
}

$r = new ReflectionClass(A::class);
$init = function (A $obj) {
    $obj->__construct();
};
$o = $r->newLazyGhost($init);
echo 'uninit=', $r->isUninitializedLazyObject($o) ? 'Y' : 'N', "\n";
$s = serialize($o);
echo $s, "\n";
echo 'uninit_after=', $r->isUninitializedLazyObject($o) ? 'Y' : 'N', "\n";
$o2 = unserialize($s);
echo 'x=', $o2->x, "\n";

$skip = $r->newLazyGhost($init, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
echo 'skip_uninit=', $r->isUninitializedLazyObject($skip) ? 'Y' : 'N', "\n";
$ss = serialize($skip);
echo $ss, "\n";
echo 'skip_uninit_after=', $r->isUninitializedLazyObject($skip) ? 'Y' : 'N', "\n";

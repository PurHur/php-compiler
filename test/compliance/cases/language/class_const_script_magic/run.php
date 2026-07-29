<?php

declare(strict_types=1);

class C { const X = __DIR__; }
echo (strlen(C::X) > 0 ? "ok" : "bad"), "\n";
class D { const Y = __FILE__; }
echo basename(D::Y), "\n";
class E { const Z = __LINE__; }
echo E::Z, "\n";
class F { const W = __DIR__ . "/z"; }
echo basename(F::W), "\n";

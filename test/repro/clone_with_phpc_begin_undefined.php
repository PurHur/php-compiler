<?php
/**
 * Issue #22856 — clone($obj, [...]) must resolve phpc_clone_with_* ABI helpers
 * (hidden from function_exists) under PHP_COMPILER_PROFILE=8.5.
 *
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/clone_with_phpc_begin_undefined.php
 */
declare(strict_types=1);

class C
{
    public function __construct(public int $x = 1, public int $y = 0)
    {
    }
}

$a = new C(1, 9);
$b = clone ($a, ['x' => 2]);
echo $b->x, ',', $b->y, "\n";

class R
{
    public readonly int $x;

    public function __construct(int $x)
    {
        $this->x = $x;
    }
}

$c = new R(1);
$d = clone($c, ['x' => 2]);
echo $d->x, "\n";

echo function_exists('phpc_clone_with_begin') ? "VISIBLE\n" : "HIDDEN\n";

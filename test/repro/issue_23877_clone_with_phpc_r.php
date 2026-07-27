<?php
/**
 * Issue #23877 — clone($obj, [...]) under PROFILE=8.5 must match Zend with zero
 * Undefined variable $__phpc_r warnings under E_ALL.
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_23877_clone_with_phpc_r.php
 * Reject under 8.4:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_23877_clone_with_phpc_r.php
 */
declare(strict_types=1);

error_reporting(E_ALL);

class T
{
    public function __construct(public int $x, public string $y = 'a')
    {
    }
}

$t = new T(1, 'b');
$u = clone($t, ['x' => 9]);
echo 'u.x=', $u->x, ' u.y=', $u->y, "\n";

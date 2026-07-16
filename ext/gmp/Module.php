<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;

/** gmp extension module entry (php-src ext/gmp/gmp.c; issues #3341, #19527, #19539). */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        VmGmpObject::registerClass($runtime->vmContext);
    }

    public static function registerClasses(Context $ctx): void
    {
        VmGmpObject::registerClass($ctx);
    }

    public function getFunctions(): array
    {
        return [
            new gmp_init(),
            new gmp_add(),
            new gmp_sub(),
            new gmp_mul(),
            new gmp_cmp(),
            new gmp_strval(),
            new gmp_pow(),
            new gmp_mod(),
            new gmp_div_q(),
            new gmp_div_r(),
            new gmp_div_qr(),
            new gmp_abs(),
            new gmp_neg(),
            new gmp_and(),
            new gmp_or(),
            new gmp_xor(),
            new gmp_intval(),
            new gmp_powm(),
            new gmp_fact(),
            new gmp_gcd(),
            new gmp_lcm(),
            new gmp_sqrt(),
            new gmp_sqrtrem(),
            new gmp_perfect_square(),
            new gmp_com(),
        ];
    }
}

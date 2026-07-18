<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;

/** gmp extension module entry (php-src ext/gmp/gmp.c; issues #3341, #19527, #19539, #19540, #20519). */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        VmGmpObject::registerClass($runtime->vmContext);
        foreach ([
            'GMP_MSW_FIRST' => VmGmp::GMP_MSW_FIRST,
            'GMP_LSW_FIRST' => VmGmp::GMP_LSW_FIRST,
            'GMP_LITTLE_ENDIAN' => VmGmp::GMP_LITTLE_ENDIAN,
            'GMP_BIG_ENDIAN' => VmGmp::GMP_BIG_ENDIAN,
            'GMP_NATIVE_ENDIAN' => VmGmp::GMP_NATIVE_ENDIAN,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
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
            new gmp_binomial(),
            new gmp_gcd(),
            new gmp_lcm(),
            new gmp_sqrt(),
            new gmp_sqrtrem(),
            new gmp_perfect_square(),
            new gmp_com(),
            new gmp_random_seed(),
            new gmp_random_bits(),
            new gmp_random_range(),
            new gmp_import(),
            new gmp_export(),
            new gmp_sign(),
            new gmp_prob_prime(),
            new gmp_nextprime(),
            new gmp_invert(),
            new gmp_jacobi(),
            new gmp_legendre(),
            new gmp_gcdext(),
            new gmp_root(),
            new gmp_rootrem(),
            new gmp_perfect_power(),
            new gmp_testbit(),
            new gmp_setbit(),
            new gmp_clrbit(),
            new gmp_scan0(),
            new gmp_scan1(),
            new gmp_popcount(),
            new gmp_hamdist(),
        ];
    }
}

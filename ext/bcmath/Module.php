<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * bcmath extension module entry (php-src ext/bcmath/bcmath.c; issue #5924).
 *
 * Arithmetic in {@see VmBcmath} (issue #3365 / #5969).
 * Register under {@see standard}; advertise logical {@code bcmath} and bc*()
 * only when {@see CompilerVersion::supportsBcmath()} — withheld on reference profile (#12131).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!CompilerVersion::supportsBcmath()) {
            return [];
        }

        return ['bcmath'];
    }

    public function jitInit(JIT\Context $context): void
    {
        if (!CompilerVersion::supportsBcmath()) {
            return;
        }

        // VALUE⊙VALUE / OBJECT⊙OBJECT do_operation — core must not import ext\bcmath (#36204 / #24683).
        $context->arithBinaryValueValueHook = static function (
            JIT\Context $ctx,
            int $opType,
            Variable $left,
            Variable $right
        ): Variable {
            return JitBcMathNumberOperators::binaryValueValue($ctx, $opType, $left, $right);
        };
        $context->arithBinaryObjectObjectHook = static function (
            JIT\Context $ctx,
            int $opType,
            Variable $left,
            Variable $right
        ): Variable {
            return JitBcMathNumberOperators::binaryObjectObject($ctx, $opType, $left, $right);
        };

        // php-src ext/bcmath/bcmath.stub.php — readonly value/scale (#24683, #7220 / #36204).
        $context->type->object->registerExternalClassSeeder('bcmath\\number', static function ($obj, int $id): void {
            $obj->defineProperty($id, VmBcMathNumber::PROP_VALUE, Variable::TYPE_STRING);
            $obj->defineProperty($id, VmBcMathNumber::PROP_SCALE, Variable::TYPE_NATIVE_LONG);
            $obj->setClassReadonly($id, true);
            $obj->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'add', 'sub', 'mul', 'div', 'mod', 'divmod', 'powmod', 'pow',
                'sqrt', 'floor', 'ceil', 'round', 'compare', '__tostring',
                '__serialize', '__unserialize',
            ] as $method) {
                $obj->defineMethodVisibility($id, $method, $pub);
            }
        });
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!CompilerVersion::supportsBcmath()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!CompilerVersion::supportsBcmath()) {
            return [];
        }

        return [
            new bcadd(),
            new bcsub(),
            new bcmul(),
            new bcdiv(),
            new bcdivmod(),
            new bcmod(),
            new bcpow(),
            new bcsqrt(),
            new bcscale(),
            new bccomp(),
            new bcpowmod(),
            new bcceil(),
            new bcfloor(),
            new bcround(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** GMP object registration (php-src ext/gmp/gmp.c; issue #3341). */
final class VmGmpObject
{
    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[VmGmp::CLASS_LC])) {
            return;
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('GMP');
        $entry->isInternal = true;
        $entry->properties[] = new ClassProperty(VmGmp::PROP_VALUE, null, $strProto, true);

        $entry->methods['__tostring'] = new GmpToString();
        $entry->methodVisibility['__tostring'] = $pub;

        $ctx->classes[VmGmp::CLASS_LC] = $entry;
    }

    public static function fromSignedDecimal(Context $ctx, string $signedDecimal): Variable
    {
        $class = $ctx->classes[VmGmp::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('GMP is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        VmGmp::initObject($entry, $signedDecimal);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function requireGmp(Variable $var, string $function, int $index, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GMP, %s given',
                $function,
                $index + 1,
                $label,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $object = $resolved->toObject();
        if (VmGmp::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GMP, %s given',
                $function,
                $index + 1,
                $label,
                $object->class->name
            ));
        }

        return $object;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ReflectionSupport;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** Locale::lookup() — php-src locale_methods.c (#20036). */
final class LocaleLookup extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('lookup');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'Locale::lookup() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'Locale::lookup() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $tags = self::coerceStringList($frame->calledArgs[0], 'Locale::lookup', 0, 'languageTag');
        $locale = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'Locale::lookup', 1, 'locale');
        $canonicalize = false;
        if ($argc >= 3) {
            $canonicalize = self::coerceBool($frame->calledArgs[2], 'Locale::lookup', 2, 'canonicalize');
        }
        $default = null;
        if ($argc >= 4) {
            $defaultVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $defaultVar->type) {
                $default = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[3],
                    'Locale::lookup',
                    3,
                    'defaultLocale'
                );
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLocale::lookup($tags, $locale, $canonicalize, $default));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLocaleLookup::lookup($context, ...$args);
    }

    /** @return list<string> */
    public static function coerceStringList(Variable $var, string $function, int $position, string $name): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $function,
                $position + 1,
                $name,
                ReflectionSupport::valueTypeLabelPublic($var)
            ));
        }

        return self::exportStringList($var->toArray(), $function);
    }

    /** @return list<string> */
    public static function exportStringList(HashTable $ht, string $function): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [, $value]) {
            $value = $value->resolveIndirect();
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \TypeError(
                    $function.'(): Argument #1 ($languageTag) must only contain string values'
                );
            }
            $out[] = $value->toString();
        }

        return $out;
    }

    public static function coerceBool(Variable $var, string $function, int $position, string $name): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type bool, %s given',
                $function,
                $position + 1,
                $name,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return false;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return 0 !== $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return 0.0 !== $var->toFloat();
        }
        if (Variable::TYPE_STRING === $var->type) {
            $s = $var->toString();

            return '' !== $s && '0' !== $s;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type bool, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }
}

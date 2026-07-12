<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ldap_escape() — filter/DN escaping (php-src ext/ldap/ldap.c; issue #6352). */
final class ldap_escape extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_escape');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('ldap_escape() expects between 1 and 3 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $value = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ldap_escape', 0, 'value');
        $ignore = '';
        if ($argc >= 2) {
            $ignore = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_escape', 1, 'ignore');
        }
        $flags = 0;
        if (3 === $argc) {
            $flags = self::parseFlagsArg($frame->calledArgs[2]);
        }

        $frame->returnVar->string(VmLdapEscape::escape($value, $ignore, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapEscape::invoke($context, $args);
    }

    private static function parseFlagsArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                'ldap_escape(): Argument #3 ($flags) must be of type int, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                'ldap_escape(): Argument #3 ($flags) must be of type int, %s given',
                self::vmTypeName($var->type)
            ));
        }

        return $var->toInt();
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * constant() — resolve a global/user constant by name (issue #3813).
 *
 * php-src: ext/standard/basic_functions.c — zif_constant
 */
final class constant_ extends Internal
{
    private const NAME_TYPE_ERROR =
        'constant(): Argument #1 ($name) must be of type string, %s given';

    public function __construct()
    {
        parent::__construct('constant');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('constant() requires exactly one argument');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError(\sprintf(
                self::NAME_TYPE_ERROR,
                EnumCaseSupport::isEnumCaseVariable($nameVar)
                    ? EnumCaseSupport::typeNameForVariable($nameVar)
                    : self::vmTypeName($nameVar->type)
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('constant() requires VM context');
        }
        $name = $nameVar->toString();
        $value = VmConstants::constantLookup($frame->vmContext, $name);
        if (null !== $value) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->copyFrom($value);
            }

            return;
        }
        throw new \Error('Undefined constant "'.$name.'"');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('constant() requires exactly one argument');
        }

        if (JITVariable::TYPE_VALUE === $args[0]->type || JITVariable::TYPE_OBJECT === $args[0]->type) {
            JitStringBuiltinArg::lower($context, $args[0], 'constant', 0, 'name');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            self::emitJitTypeErrorAndAbort($context, self::jitTypeErrorMessage($context, $args[0]));
            $ptrType = $context->getTypeFromString('__value__*');

            return $ptrType->constNull();
        }

        return JitConstant::invoke($context, $args[0]);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeErrorMessage(Context $context, JITVariable $arg): string
    {
        return \sprintf(self::NAME_TYPE_ERROR, JitOperandTypeLabel::givenLabel($context, $arg));
    }

    private static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}

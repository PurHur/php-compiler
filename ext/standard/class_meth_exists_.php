<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * class_meth_exists() — PHP 8.4 class-string method probe (#7068).
 *
 * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(class_meth_exists)
 */
final class class_meth_exists_ extends Internal
{
    private const CLASS_TYPE_ERROR = 'class_meth_exists(): Argument #1 ($class) must be of type string, %s given';

    public function __construct()
    {
        parent::__construct('class_meth_exists');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('class_meth_exists() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $className = self::requireClassString($frame->calledArgs[0]);
        $method = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'class_meth_exists', 1, 'method');
        $exists = VmReflection::classMethExists($ctx, $className, $method);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('class_meth_exists() requires exactly two arguments');
        }
        self::emitJitStringArgChecks($context, $args[0], 0, 'class');
        self::emitJitStringArgChecks($context, $args[1], 1, 'method');

        $classLiteral = JitStringArg::compileTimeLiteral($args[0]);
        $methodLiteral = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $classLiteral && null !== $methodLiteral) {
            return ReflectionBuiltinHelper::methodExistsLiteral($context, $classLiteral, $methodLiteral);
        }

        throw new \LogicException(
            'class_meth_exists() class and method names must be string literals in JIT in this compiler build'
        );
    }

    private static function requireClassString(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                'class_meth_exists(): Argument #1 ($class) must be of type string, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        throw new \TypeError(\sprintf(self::CLASS_TYPE_ERROR, self::vmTypeName($var->type)));
    }

    private static function emitJitStringArgChecks(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): void {
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            return;
        }
        if (0 === $argIndex && JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitJitTypeErrorAndAbort($context, \sprintf(self::CLASS_TYPE_ERROR, 'object'));

            return;
        }
        JitStringBuiltinArg::lower($context, $arg, 'class_meth_exists', $argIndex, $paramName);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
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
            case Variable::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** get_class() — class name of an object (issue #1217, #5456). */
final class get_class_ extends Internal
{
    private const TYPE_ERROR = 'get_class(): Argument #1 ($object) must be of type object, %s given';

    public function __construct()
    {
        parent::__construct('get_class');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('get_class() expects at most 1 argument, %d given', $argc)
            );
        }
        if (0 === $argc) {
            $definingClass = VmReflection::zeroArgGetClassName($frame);
            BuiltinExecute::writeReturn(
                $frame,
                static function (Variable $ret) use ($definingClass): void {
                    $ret->string($definingClass);
                }
            );

            return;
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        BuiltinExecute::writeReturn(
            $frame,
            function (Variable $ret) use ($value): void {
                if (Variable::TYPE_ENUM_CASE === $value->type) {
                    $ret->string($value->toEnumCase()->enumClass->name);

                    return;
                }
                if (ResourceSupport::rejectsGetClassOperand($value)) {
                    throw new \TypeError(\sprintf(self::TYPE_ERROR, 'resource'));
                }
                if (Variable::TYPE_OBJECT !== $value->type) {
                    throw new \TypeError(\sprintf(self::TYPE_ERROR, self::vmTypeName($value->type)));
                }
                $ret->string($value->toObject()->class->name);
            }
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 === \count($args)) {
            return JitGetClass::invokeNoArg($context);
        }
        if (1 !== \count($args)) {
            throw new \LogicException('get_class() expects at most one argument in this compiler build');
        }

        return JitGetClass::invoke($context, $args[0]);
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_class() requires exactly one argument in this compiler build');
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $frame->returnVar->string($value->toEnumCase()->enumClass->name);

            return;
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            throw new \TypeError(\sprintf(self::TYPE_ERROR, self::vmTypeName($value->type)));
        }
        $frame->returnVar->string($value->toObject()->class->name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_class() requires exactly one argument in this compiler build');
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

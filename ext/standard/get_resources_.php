<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_resources() — list active stream resources (issue #3646).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_resources)
 */
final class get_resources_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_resources');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 1) {
            throw new \LogicException('get_resources() takes at most one argument');
        }
        $table = VmFs::getResourcesTable(self::optionalType($frame));
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($table);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('get_resources() takes at most one argument');
        }

        return JitGetResources::invoke($context, $args[0] ?? null);
    }

    private static function optionalType(Frame $frame): ?string
    {
        if (0 === \count($frame->calledArgs)) {
            return null;
        }
        $typeVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $typeVar->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $typeVar->type) {
            throw new \LogicException('get_resources() expects string or null for argument 1');
        }

        return self::normalizeType($typeVar->toString());
    }

    /** @throws \ValueError */
    public static function normalizeType(string $type): string
    {
        if ('stream' !== $type) {
            throw new \ValueError('get_resources(): Argument #1 ($type) must be a valid resource type');
        }

        return $type;
    }
}

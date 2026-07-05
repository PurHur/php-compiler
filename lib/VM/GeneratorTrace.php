<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\GeneratorGetReturn;

/**
 * ReflectionGenerator execute-site introspection (issue #5964; php-src ext/reflection/php_reflection.c).
 */
final class GeneratorTrace
{
    public static function requireGeneratorObject(Variable $arg, string $function, int $param): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($generator) must be of type Generator, %s given',
                $function,
                $param,
                VmStreamArg::debugTypeName($arg)
            ));
        }
        $object = $arg->toObject();
        if (null === $object->generatorState) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($generator) must be of type Generator, %s given',
                $function,
                $param,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function generatorStateFromReflection(ObjectEntry $reflection): GeneratorState
    {
        $target = $reflection->getProperty(ReflectionSupport::PROP_GENERATOR_TARGET)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $target->type) {
            throw new \LogicException('ReflectionGenerator missing wrapped generator');
        }
        $object = $target->toObject();

        return GeneratorGetReturn::requireGeneratorState($object);
    }

    public static function requireActiveGenerator(GeneratorState $gen): void
    {
        if ($gen->done) {
            throw new \ReflectionException('Cannot fetch information from a terminated Generator');
        }
    }

    public static function executingLine(GeneratorState $gen): int
    {
        self::requireActiveGenerator($gen);
        $frame = self::resolveExecuteFrame($gen);
        if (null === $frame) {
            return 0;
        }

        $line = FatalSite::lineFromOpcodes($frame);
        if ($line > 0) {
            return $line;
        }

        return self::firstSourceLineInBlock($frame);
    }

    private static function firstSourceLineInBlock(Frame $frame): int
    {
        if (null === $frame->block) {
            return 0;
        }
        foreach ($frame->block->opCodes as $op) {
            if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                return $op->sourceLocation->startLine;
            }
        }

        return 0;
    }

    public static function executingFile(GeneratorState $gen): string|false
    {
        self::requireActiveGenerator($gen);
        $frame = self::resolveExecuteFrame($gen);
        if (null === $frame) {
            return false;
        }
        if (null !== $frame->block) {
            $path = $frame->block->scriptPath();
            if ('' !== $path) {
                return $path;
            }
        }
        if ('' !== $frame->scriptPath) {
            return $frame->scriptPath;
        }

        return false;
    }

    private static function resolveExecuteFrame(GeneratorState $gen): ?Frame
    {
        if (null !== $gen->frame) {
            return $gen->frame;
        }

        $ctx = $gen->vm->context;
        if (null === $ctx) {
            return null;
        }

        return $gen->func->block->getFrame($ctx, null);
    }
}

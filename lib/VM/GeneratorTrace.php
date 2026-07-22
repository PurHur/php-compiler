<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\GeneratorGetReturn;

/**
 * ReflectionGenerator execute-site introspection (issue #5964/#22067; php-src ext/reflection/php_reflection.c).
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

    /**
     * ReflectionGenerator::getThis() — php-src zim_reflection_generator_getThis (#22067).
     *
     * Returns the bound object receiver, or null for function / static generators.
     */
    public static function boundThis(GeneratorState $gen): ?Variable
    {
        self::requireActiveGenerator($gen);
        $frame = self::resolveExecuteFrame($gen);
        if (null !== $frame) {
            self::ensureFrameThisBound($frame, $gen);
            $fromFrame = self::thisFromFrame($frame);
            if (null !== $fromFrame) {
                return $fromFrame;
            }
        }

        return self::thisFromGeneratorState($gen);
    }

    /**
     * ReflectionGenerator::getTrace($options) — php-src zim_reflection_generator_getTrace (#22067).
     *
     * Builds a backtrace rooted at the generator execute frame (caller chain detached),
     * matching zend_fetch_debug_backtrace after nulling prev_execute_data.
     */
    public static function buildTrace(GeneratorState $gen, int $options): Variable
    {
        self::requireActiveGenerator($gen);
        $frame = self::resolveExecuteFrame($gen);
        if (null === $frame) {
            $empty = new Variable();
            $empty->newArray();

            return $empty;
        }
        $frame->calledArgs = $gen->calledArgs;
        self::ensureFrameThisBound($frame, $gen);

        return VmDebugBacktrace::buildFromFrames([$frame], $options);
    }

    /** Bind $this into a freshly created generator frame (instance methods / bound closures). */
    public static function ensureFrameThisBound(Frame $frame, GeneratorState $gen): void
    {
        if (null === $frame->block) {
            return;
        }
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx) {
            return;
        }
        if (isset($frame->scope[$thisIdx])) {
            $bound = $frame->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return;
            }
        }
        $thisVar = self::thisFromFrame($frame);
        if (null === $thisVar) {
            $thisVar = self::thisFromGeneratorState($gen);
        }
        if (null === $thisVar) {
            return;
        }
        if (!isset($frame->scope[$thisIdx])) {
            $frame->scope[$thisIdx] = new Variable();
        }
        $frame->scope[$thisIdx]->copyFrom($thisVar);
    }

    private static function thisFromFrame(Frame $frame): ?Variable
    {
        if (null === $frame->block || null === $frame->block->func) {
            return null;
        }
        $func = $frame->block->func;
        if (null === $func->class && (($func->flags ?? 0) & CfgFunc::FLAG_CLOSURE) === 0) {
            return null;
        }
        if ((($func->flags ?? 0) & CfgFunc::FLAG_STATIC) !== 0) {
            return null;
        }
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null !== $thisIdx && isset($frame->scope[$thisIdx])) {
            $bound = $frame->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $frame->scope[$thisIdx];
            }
        }
        $fromScope = $frame->block->findVariableByRuntimeName('this', $frame);
        if (null !== $fromScope) {
            $bound = $fromScope->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $fromScope;
            }
        }
        if (!empty($frame->calledArgs)) {
            $receiver = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                return $frame->calledArgs[0];
            }
        }

        return null;
    }

    private static function thisFromGeneratorState(GeneratorState $gen): ?Variable
    {
        if (null !== $gen->closureCall && null !== $gen->closureCall->boundThis) {
            $bound = $gen->closureCall->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $gen->closureCall->boundThis;
            }
        }
        $func = $gen->func->block->func ?? null;
        if (null === $func || null === $func->class) {
            return null;
        }
        if ((($func->flags ?? 0) & CfgFunc::FLAG_STATIC) !== 0) {
            return null;
        }
        if ([] === $gen->calledArgs) {
            return null;
        }
        $receiver = $gen->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            return null;
        }

        return $gen->calledArgs[0];
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
        $frame = $gen->func->block->getFrame($ctx, null);
        $frame->calledArgs = $gen->calledArgs;
        $frame->generatorState = $gen;

        return $frame;
    }
}

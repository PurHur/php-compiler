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
 * unserialize() — assoc arrays with scalar values (VM delegates to PHP; JIT/AOT via __compiler_unserialize).
 */
final class unserialize extends Internal
{
    public function __construct()
    {
        parent::__construct('unserialize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('unserialize() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dataVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $dataVar->type) {
            throw new \LogicException('unserialize() first argument must be a string in this compiler build');
        }
        $options = ['allowed_classes' => false];
        if ($argc > 1) {
            self::mergeOptions($frame->calledArgs[1]->resolveIndirect(), $options);
        }
        if ($argc > 2) {
            throw new \LogicException('unserialize() extra arguments not supported in this compiler build');
        }
        $decoded = @\unserialize($dataVar->toString(), $options);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('unserialize() requires at least one argument');
        }
        if (\count($args) > 2) {
            throw new \LogicException('unserialize() extra arguments not supported in this compiler build');
        }

        $compileTime = JitUnserialize::compileTimeDecode($context, $args[0]);
        if (null !== $compileTime) {
            return $compileTime;
        }

        return JitUnserialize::decodeRuntime($context, $args[0]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function mergeOptions(Variable $arg, array &$options): void
    {
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \LogicException('unserialize() options must be an array in this compiler build');
        }
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            if ('allowed_classes' !== $key->toString()) {
                continue;
            }
            $value = $valueVar->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $value->type) {
                $options['allowed_classes'] = $value->toBool();

                continue;
            }
            if (Variable::TYPE_ARRAY === $value->type) {
                $classes = [];
                foreach ($value->toArray()->iterateIndexed() as $item) {
                    $item = $item->resolveIndirect();
                    if (Variable::TYPE_STRING === $item->type) {
                        $classes[] = $item->toString();
                    }
                }
                $options['allowed_classes'] = $classes;
            }
        }
    }
}

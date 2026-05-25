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
 * stream_context_create() — VM returns array stream-context representation (#1377).
 *
 * JIT: {@see JitStreamContextCreate} (zero-arg empty context; options deferred, #1377).
 */
final class stream_context_create extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \LogicException(
                'stream_context_create() accepts at most two arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $options = [];
        if ($argc >= 1) {
            $optVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \LogicException(
                    'stream_context_create() argument #1 must be an array in this compiler build'
                );
            }
            $exported = VmHttpBuildQuery::export($optVar);
            if (!\is_array($exported)) {
                throw new \LogicException(
                    'stream_context_create() argument #1 must be an array in this compiler build'
                );
            }
            $options = $exported;
        }

        $params = null;
        if (2 === $argc) {
            $paramsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $paramsVar->type) {
                throw new \LogicException(
                    'stream_context_create() argument #2 must be an array in this compiler build'
                );
            }
            $exportedParams = VmHttpBuildQuery::export($paramsVar);
            if (!\is_array($exportedParams)) {
                throw new \LogicException(
                    'stream_context_create() argument #2 must be an array in this compiler build'
                );
            }
            $params = $exportedParams;
        }

        $frame->returnVar->array(VmStreamContext::create($options, $params));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStreamContextCreate::invoke($context, ...$args);
    }
}

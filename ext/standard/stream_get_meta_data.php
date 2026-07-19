<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_get_meta_data() — stream resource introspection (issue #6007).
 *
 * Also registered as socket_get_status() via PHP_FALIAS (issue #20903).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_get_meta_data)
 * php-src: ext/standard/basic_functions.stub.php — @alias stream_get_meta_data
 */
final class stream_get_meta_data extends Internal
{
    public function __construct(string $name = 'stream_get_meta_data')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException($fn.'() requires exactly one argument in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            $fn
        );
        if (null === $frame->returnVar) {
            return;
        }
        $meta = VmFs::streamGetMetaData($handle);
        if (false === $meta) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($meta);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (1 !== \count($args)) {
            throw new \LogicException($fn.'() requires exactly one argument in this compiler build');
        }

        return JitStreamGetMetaData::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], $fn.'() stream'),
                $context->getTypeFromString('int64')
            )
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_socket_pair() — connected socket stream pair (php-src ext/standard/streams.c; #3437).
 *
 * VM: {@see VmStreamSocketPairNative}; JIT/AOT: {@see JitStreamSocketPair} / __compiler_stream_socket_pair.
 */
final class stream_socket_pair extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_pair');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_socket_pair() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $domain = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_pair',
            1,
            'domain'
        );
        $type = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_socket_pair',
            2,
            'type'
        );
        $protocol = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[2]->resolveIndirect(),
            'stream_socket_pair',
            3,
            'protocol'
        );

        $pair = VmStreamSocketPairNative::pair($domain, $type, $protocol);
        if (false === $pair) {
            $frame->returnVar->bool(false);

            return;
        }

        [$stream0, $stream1, $fd0, $fd1] = $pair;
        $handle0 = VmFs::adoptStreamResource($stream0, 'unix://stream_socket_pair', $fd0);
        $handle1 = VmFs::adoptStreamResource($stream1, 'unix://stream_socket_pair', $fd1);
        if (false === $handle0 || false === $handle1) {
            $frame->returnVar->bool(false);

            return;
        }

        $out = new HashTable();
        $slot0 = new Variable();
        $slot0->streamHandle($handle0, $frame->vmContext);
        $out->addIndex(0, $slot0);
        $slot1 = new Variable();
        $slot1->streamHandle($handle1, $frame->vmContext);
        $out->addIndex(1, $slot1);
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        \PHPCompiler\JIT\Builtin\StreamSocketPair::ensureLinked($context);

        return JitStreamSocketPair::invoke($context, ...$args);
    }
}

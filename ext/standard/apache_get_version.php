<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT;
use PHPLLVM\Value;

/**
 * apache_get_version() — Apache server version string (#6276).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(apache_get_version)
 */
final class apache_get_version extends Internal
{
    public function __construct()
    {
        parent::__construct('apache_get_version');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(\sprintf(
                'apache_get_version() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = VmApache::getVersion($frame);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(\sprintf(
                'apache_get_version() expects exactly 0 arguments, %d given',
                \count($args)
            ));
        }
        if (!VmApache::isApacheSapi()) {
            return apache_note::emitUnavailableJit($context, ApacheNoteJitHelper::class.'::versionUnavailable');
        }

        throw new \LogicException('apache_get_version() Apache SAPI JIT lowering is deferred (#6276)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** posix_uname() — system identification (php-src ext/posix/posix.c; #6123). */
final class posix_uname extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_uname');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('posix_uname() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $raw = VmPosix::uname();
        if (false === $raw) {
            if (null !== $frame->vmContext) {
                $errno = VmPosix::getLastError();
                $frame->vmContext->errors->triggerError(
                    'posix_uname(): '.VmPosix::strerror($errno),
                    ErrorReporter::E_WARNING,
                    $frame->scriptPath,
                    $frame->callSiteLine
                );
            }
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($raw as $key => $value) {
            $slot = new Variable();
            $slot->string((string) $value);
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_uname() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        throw new \Error('posix_uname() is not implemented for JIT in this compiler build (issue #6123)');
    }
}

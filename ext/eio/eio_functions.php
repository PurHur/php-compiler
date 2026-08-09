<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** Shared JIT stub for eio_* v1 (#6442). */
abstract class EioFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #6442)');
    }

    protected function optionalCallback(Frame $frame, int $index): Variable
    {
        if (!isset($frame->calledArgs[$index])) {
            $null = new Variable(Variable::TYPE_NULL);

            return $null;
        }
        $cb = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $cb->type) {
            return $cb;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx || !VmCallable::isCallable($ctx, $cb)) {
            throw new \TypeError($this->getName().'(): Argument #'.($index + 1).' ($callback) must be of type callable');
        }

        return $cb;
    }

    protected function optionalData(Frame $frame, int $index): Variable
    {
        if (!isset($frame->calledArgs[$index])) {
            return new Variable(Variable::TYPE_NULL);
        }

        return $frame->calledArgs[$index]->resolveIndirect();
    }
}

final class eio_init extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_init');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('eio_init() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmEioCore::init());
    }
}

final class eio_nreqs extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_nreqs');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('eio_nreqs() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmEioCore::nreqs());
    }
}

final class eio_poll extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_poll');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('eio_poll() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_poll() requires a VM context');
        }
        $frame->returnVar->int(VmEioCore::poll($ctx));
    }
}

final class eio_nop extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_nop');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 3) {
            throw new \ArgumentCountError('eio_nop() expects at most 3 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_nop() requires a VM context');
        }
        $pri = 0 === $argc ? EioConstants::EIO_PRI_DEFAULT : VmEioCore::resolvePri($frame->calledArgs[0] ?? null);
        $cb = $this->optionalCallback($frame, 1);
        $data = $this->optionalData($frame, 2);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'nop', $cb, $data, $pri, []));
    }
}

final class eio_open extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 6) {
            throw new \ArgumentCountError('eio_open() expects between 3 and 6 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_open() requires a VM context');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'eio_open', 0, 'path');
        $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'eio_open', 2, 'flags');
        $mode = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'eio_open', 3, 'mode');
        unset($mode);
        $pri = $argc >= 4 ? VmEioCore::resolvePri($frame->calledArgs[3]) : EioConstants::EIO_PRI_DEFAULT;
        $cb = $this->optionalCallback($frame, 4);
        $data = $this->optionalData($frame, 5);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'open', $cb, $data, $pri, [
            'path' => $path,
            'flags' => $flags,
        ]));
    }
}

final class eio_close extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError('eio_close() expects between 1 and 4 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_close() requires a VM context');
        }
        $fd = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'eio_close', 1, 'fd');
        $pri = $argc >= 2 ? VmEioCore::resolvePri($frame->calledArgs[1]) : EioConstants::EIO_PRI_DEFAULT;
        $cb = $this->optionalCallback($frame, 2);
        $data = $this->optionalData($frame, 3);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'close', $cb, $data, $pri, ['fd' => $fd]));
    }
}

final class eio_read extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_read');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 6) {
            throw new \ArgumentCountError('eio_read() expects between 4 and 6 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_read() requires a VM context');
        }
        $fd = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'eio_read', 1, 'fd');
        $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'eio_read', 2, 'length');
        $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'eio_read', 3, 'offset');
        $pri = VmEioCore::resolvePri($frame->calledArgs[3]);
        $cb = $this->optionalCallback($frame, 4);
        $data = $this->optionalData($frame, 5);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'read', $cb, $data, $pri, [
            'fd' => $fd,
            'length' => $length,
            'offset' => $offset,
        ]));
    }
}

final class eio_write extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_write');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // PECL: eio_write(fd, str, length, offset, pri, callback, data)
        if ($argc < 4 || $argc > 7) {
            throw new \ArgumentCountError('eio_write() expects between 4 and 7 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_write() requires a VM context');
        }
        $fd = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'eio_write', 1, 'fd');
        $str = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'eio_write', 1, 'str');
        $length = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'eio_write', 3, 'length');
        $offset = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'eio_write', 4, 'offset');
        $pri = EioConstants::EIO_PRI_DEFAULT;
        $cbIndex = 4;
        $dataIndex = 5;
        if ($argc >= 5) {
            $maybePri = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $maybePri->type || Variable::TYPE_NULL === $maybePri->type) {
                $pri = VmEioCore::resolvePri($frame->calledArgs[4]);
                $cbIndex = 5;
                $dataIndex = 6;
            }
        }
        $cb = $this->optionalCallback($frame, $cbIndex);
        $data = $this->optionalData($frame, $dataIndex);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'write', $cb, $data, $pri, [
            'fd' => $fd,
            'str' => $str,
            'length' => $length < 0 ? \strlen($str) : $length,
            'offset' => $offset,
        ]));
    }
}

final class eio_stat extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_stat');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError('eio_stat() expects between 3 and 4 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_stat() requires a VM context');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'eio_stat', 0, 'path');
        $pri = VmEioCore::resolvePri($frame->calledArgs[1]);
        $cb = $this->optionalCallback($frame, 2);
        $data = $this->optionalData($frame, 3);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'stat', $cb, $data, $pri, [
            'path' => $path,
        ]));
    }
}

final class eio_mkdir extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_mkdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError('eio_mkdir() expects between 2 and 5 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_mkdir() requires a VM context');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'eio_mkdir', 0, 'path');
        $mode = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'eio_mkdir', 2, 'mode');
        $pri = $argc >= 3 ? VmEioCore::resolvePri($frame->calledArgs[2]) : EioConstants::EIO_PRI_DEFAULT;
        $cb = $this->optionalCallback($frame, 3);
        $data = $this->optionalData($frame, 4);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'mkdir', $cb, $data, $pri, [
            'path' => $path,
            'mode' => $mode,
        ]));
    }
}

final class eio_unlink extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_unlink');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError('eio_unlink() expects between 1 and 4 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_unlink() requires a VM context');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'eio_unlink', 0, 'path');
        $pri = $argc >= 2 ? VmEioCore::resolvePri($frame->calledArgs[1]) : EioConstants::EIO_PRI_DEFAULT;
        $cb = $this->optionalCallback($frame, 2);
        $data = $this->optionalData($frame, 3);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'unlink', $cb, $data, $pri, [
            'path' => $path,
        ]));
    }
}

final class eio_chmod extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_chmod');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError('eio_chmod() expects between 2 and 5 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_chmod() requires a VM context');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'eio_chmod', 0, 'path');
        $mode = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'eio_chmod', 2, 'mode');
        $pri = $argc >= 3 ? VmEioCore::resolvePri($frame->calledArgs[2]) : EioConstants::EIO_PRI_DEFAULT;
        $cb = $this->optionalCallback($frame, 3);
        $data = $this->optionalData($frame, 4);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'chmod', $cb, $data, $pri, [
            'path' => $path,
            'mode' => $mode,
        ]));
    }
}

final class eio_readdir extends EioFunction
{
    public function __construct()
    {
        parent::__construct('eio_readdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError('eio_readdir() expects between 4 and 5 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('eio_readdir() requires a VM context');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'eio_readdir', 0, 'path');
        $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'eio_readdir', 2, 'flags');
        $pri = VmEioCore::resolvePri($frame->calledArgs[2]);
        $cb = $this->optionalCallback($frame, 3);
        $data = $this->optionalData($frame, 4);
        $frame->returnVar->copyFrom(VmEioCore::enqueue($ctx, 'readdir', $cb, $data, $pri, [
            'path' => $path,
            'flags' => $flags,
        ]));
    }
}

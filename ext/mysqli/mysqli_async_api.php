<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Async poll/reap API (#22163).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli.c
 *   mysqli_poll, mysqli_reap_async_query
 */

final class mysqli_poll extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_poll');
    }

    public function execute(Frame $frame): void
    {
        VmMysqli::executePoll($frame, 'mysqli_poll', 0);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_poll() is not implemented for JIT (issue #22163)');
    }
}

final class mysqli_reap_async_query extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_reap_async_query');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_reap_async_query');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_reap_async_query() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::reapAsyncQueryOnLink($obj, $ctx);
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_reap_async_query() is not implemented for JIT (issue #22163)');
    }
}

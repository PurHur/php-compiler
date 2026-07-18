<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * curl_multi_info_read() — read completed multi messages (php-src ext/curl/multi.c; #20495).
 *
 * Signature: curl_multi_info_read(CurlMultiHandle $multi_handle, &$queued_messages = null): array|false
 * Argument #2 is by-ref via {@see \PHPCompiler\BuiltinByRefParams}.
 */
final class curl_multi_info_read extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_info_read');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                $argc < 1
                    ? 'curl_multi_info_read() expects at least 1 argument, %d given'
                    : 'curl_multi_info_read() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_info_read', 1);
        [$info, $queued] = VmCurlMulti::infoRead($multi);
        if ($argc >= 2) {
            $frame->calledArgs[1]->resolveIndirect()->int($queued);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $info) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        $msg = new Variable();
        $msg->int($info['msg']);
        $ht->add('msg', $msg);
        $result = new Variable();
        $result->int($info['result']);
        $ht->add('result', $result);
        if (null !== $info['handle']) {
            $handle = new Variable(Variable::TYPE_OBJECT);
            $handle->object($info['handle']);
            $ht->add('handle', $handle);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_info_read() is not implemented for JIT in this compiler build (issue #20495)');
    }
}

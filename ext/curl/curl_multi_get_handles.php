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
 * curl_multi_get_handles() — list easy handles on a multi (php-src ext/curl/multi.c; #20520).
 *
 * PHP 8.5+ only (php-src NEWS). Signature: curl_multi_get_handles(CurlMultiHandle $multi_handle): array
 */
final class curl_multi_get_handles extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_get_handles');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_get_handles() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_get_handles', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach (VmCurlMulti::getHandles($multi) as $easy) {
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($easy);
            $ht->append($slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_get_handles() is not implemented for JIT in this compiler build (issue #20520)');
    }
}

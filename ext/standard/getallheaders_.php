<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

/**
 * getallheaders() — request headers from $_SERVER HTTP_* entries (issue #307).
 */
final class getallheaders_ extends Internal
{
    public function __construct()
    {
        parent::__construct('getallheaders');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }

        $ht = new HashTable();
        foreach (Superglobals::collectRequestHeaders() as $name => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add($name, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('getallheaders() takes no arguments');
        }

        return JitGetallheaders::invoke($context);
    }
}

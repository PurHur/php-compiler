<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal native hashtable string key + object value write (#33686).
 *
 * NestedJIT ArrayObject bag restore for nested `O:` values — peers set_string_key_ht (#33681).
 * php-src: ext/spl/spl_array.c — storage bag; Zend HashTable object zval
 */
final class phpc_native_ht_set_string_key_object extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_set_string_key_object');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_ht_set_string_key_object() is JIT-only (#33686)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('phpc_native_ht_set_string_key_object() expects 3 arguments');
        }
        ParseStrNativeOpsJit::setStringKeyObject($context, $args[0], $args[1], $args[2]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}

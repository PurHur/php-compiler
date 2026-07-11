<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT bridge for phpc_jit_* return-pending state via ReturnPendingJitHelper PHP (#9663).
 *
 * JIT embed and AOT standalone both call compiled {@see \PHPCompiler\ext\standard\ReturnPendingJitHelper}.
 * php-src: Zend/zend_execute.c — return-through-finally
 */
final class ReturnPendingRuntime
{
    private const HELPER_PATH = '/ext/standard/ReturnPendingJitHelper.php';

    private const H = 'PHPCompiler\\ext\\standard\\ReturnPendingJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::clearReturnPending',
        self::H.'::hasReturnPending',
        self::H.'::returnPendingIsVoid',
        self::H.'::setReturnPending',
        self::H.'::takeReturnPending',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_jit_clear_return_pending',
        'phpc_jit_has_return_pending',
        'phpc_jit_return_pending_is_void',
        'phpc_jit_set_return_pending',
        'phpc_jit_take_return_pending',
    ];

    public static function implement(Context $context): void
    {
        JitHelperAbiBridge::implement(
            $context,
            self::HELPER_PATH,
            'ReturnPendingJitHelper.php',
            '#9663',
            self::COMPILED_HELPERS,
            [
                ['abi' => 'phpc_jit_clear_return_pending', 'helper' => self::H.'::clearReturnPending', 'kind' => 'void'],
                ['abi' => 'phpc_jit_has_return_pending', 'helper' => self::H.'::hasReturnPending', 'kind' => 'bool_i32'],
                ['abi' => 'phpc_jit_return_pending_is_void', 'helper' => self::H.'::returnPendingIsVoid', 'kind' => 'bool_i32'],
                ['abi' => 'phpc_jit_set_return_pending', 'helper' => self::H.'::setReturnPending', 'kind' => 'value_i64_void'],
                ['abi' => 'phpc_jit_take_return_pending', 'helper' => self::H.'::takeReturnPending', 'kind' => 'i64_value'],
            ],
            self::ABI_FUNCTIONS,
        );
    }
}

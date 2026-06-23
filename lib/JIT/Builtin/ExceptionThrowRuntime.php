<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT bridge for phpc_jit_* throw/catch pending state via ExceptionJitHelper PHP (#9632, #9679).
 *
 * JIT embed and AOT standalone both call compiled {@see \PHPCompiler\ext\standard\ExceptionJitHelper}.
 * php-src: Zend/zend_exceptions.c — pending exception dispatch
 */
final class ExceptionThrowRuntime
{
    private const HELPER_PATH = '/ext/standard/ExceptionJitHelper.php';

    private const H = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::clearThrowPending',
        self::H.'::hasThrowPending',
        self::H.'::setThrowPending',
        self::H.'::takeThrowPending',
        self::H.'::clearActiveCatch',
        self::H.'::getActiveCatch',
        self::H.'::setActiveCatch',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_jit_clear_throw_pending',
        'phpc_jit_has_throw_pending',
        'phpc_jit_set_throw_pending',
        'phpc_jit_take_throw_pending',
        'phpc_jit_clear_active_catch',
        'phpc_jit_get_active_catch',
        'phpc_jit_set_active_catch',
    ];

    public static function implement(Context $context): void
    {
        JitHelperAbiBridge::implement(
            $context,
            self::HELPER_PATH,
            'ExceptionJitHelper.php',
            '#9679',
            self::COMPILED_HELPERS,
            [
                ['abi' => 'phpc_jit_clear_throw_pending', 'helper' => self::H.'::clearThrowPending', 'kind' => 'void'],
                ['abi' => 'phpc_jit_has_throw_pending', 'helper' => self::H.'::hasThrowPending', 'kind' => 'bool_i32'],
                ['abi' => 'phpc_jit_set_throw_pending', 'helper' => self::H.'::setThrowPending', 'kind' => 'obj_i64_void'],
                ['abi' => 'phpc_jit_take_throw_pending', 'helper' => self::H.'::takeThrowPending', 'kind' => 'i64_obj'],
                ['abi' => 'phpc_jit_clear_active_catch', 'helper' => self::H.'::clearActiveCatch', 'kind' => 'void'],
                ['abi' => 'phpc_jit_get_active_catch', 'helper' => self::H.'::getActiveCatch', 'kind' => 'i64_obj'],
                ['abi' => 'phpc_jit_set_active_catch', 'helper' => self::H.'::setActiveCatch', 'kind' => 'obj_i64_void'],
            ],
            self::ABI_FUNCTIONS,
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** phpc_jit_* goto-pending bool via GotoPendingJitHelper (#35547). */
final class GotoPendingRuntime
{
    private const HELPER_PATH = '/ext/standard/GotoPendingJitHelper.php';

    private const H = 'PHPCompiler\\ext\\standard\\GotoPendingJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::clearGotoPending',
        self::H.'::hasGotoPending',
        self::H.'::setGotoPending',
    ];

    public static function implement(Context $context): void
    {
        JitHelperAbiBridge::implement(
            $context,
            self::HELPER_PATH,
            '#35547',
            self::COMPILED_HELPERS,
            [
                ['abi' => 'phpc_jit_clear_goto_pending', 'helper' => self::H.'::clearGotoPending', 'kind' => 'void'],
                ['abi' => 'phpc_jit_has_goto_pending', 'helper' => self::H.'::hasGotoPending', 'kind' => 'bool_i32'],
                ['abi' => 'phpc_jit_set_goto_pending', 'helper' => self::H.'::setGotoPending', 'kind' => 'void'],
            ],
            [
                'phpc_jit_clear_goto_pending',
                'phpc_jit_has_goto_pending',
                'phpc_jit_set_goto_pending',
            ],
        );
    }
}

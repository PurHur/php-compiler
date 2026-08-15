<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for socket_cmsg_space() via SocketCmsgSpaceJitHelper (#31345).
 *
 * php-src: ext/sockets/sendrecvmsg.c — PHP_FUNCTION(socket_cmsg_space)
 */
final class SocketCmsgSpaceRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketCmsgSpaceJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketCmsgSpaceJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::cmsgSpaceArgv',
    ];

    private const ABI = '__compiler_socket_cmsg_space';

    private const BRIDGE_ENTRY = 'socket_cmsg_space_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$i64, $i64, $i64],
            $i64,
            self::H.'::cmsgSpaceArgv',
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31345'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}

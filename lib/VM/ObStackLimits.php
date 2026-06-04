<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Output-buffer stack limits shared by VM and JIT/AOT (issue #5582, php-src ext/standard/head.c).
 *
 * AOT still mirrors these in {@see lib/AOT/runtime/phpc_ob.c} until #5314 deletes that TU.
 */
final class ObStackLimits
{
    public const MAX_DEPTH = 8;

    public const BUF_SIZE = 65536;
}

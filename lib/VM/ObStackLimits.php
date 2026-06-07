<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Output-buffer stack limits shared by VM and JIT/AOT (issue #5582, php-src ext/standard/head.c).
 *
 * JIT/AOT mirror these in {@see \PHPCompiler\JIT\Builtin\ObOutputRuntime} (#5314).
 */
final class ObStackLimits
{
    public const MAX_DEPTH = 8;

    public const BUF_SIZE = 65536;
}

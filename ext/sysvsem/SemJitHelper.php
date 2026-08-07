<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

/**
 * Placeholder kept on the self-host spine (#28431).
 *
 * Thin-AOT sem_* owned map + SysV semget/semop/semctl live in
 * {@see \PHPCompiler\JIT\Builtin\SemRuntime} as pure LLVM — NestedJIT FFI
 * was unreliable under thin AOT (peer #28433 / #27423).
 */
final class SemJitHelper
{
}

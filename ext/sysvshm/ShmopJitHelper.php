<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

/**
 * Placeholder kept on the self-host spine (#27408).
 *
 * Thin-AOT shmop_* segment map + SysV/memcpy live in {@see \PHPCompiler\JIT\Builtin\ShmopRuntime}
 * as pure LLVM (#28433) — NestedJIT FFI/map was unreliable for pointer-sized addresses.
 */
final class ShmopJitHelper
{
}

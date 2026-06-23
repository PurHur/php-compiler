<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

/**
 * Resolves unqualified {@see Builtin} in PHPCompiler\JIT\Builtin\* to load-type constants (#1492).
 *
 * Parent class lives in {@see \PHPCompiler\JIT\Builtin}; this stub avoids PHP resolving
 * the short name to a non-existent PHPCompiler\JIT\Builtin\Builtin during AOT link.
 */
abstract class Builtin extends \PHPCompiler\JIT\Builtin
{
}

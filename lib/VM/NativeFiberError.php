<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Native error type used to model FiberError semantics.
 *
 * Zend's \FiberError is reserved for internal use (cannot be instantiated),
 * so VM internals throw this and bridge it to the builtin FiberError object.
 */
final class NativeFiberError extends \Error
{
}


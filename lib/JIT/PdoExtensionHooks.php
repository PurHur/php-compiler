<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * pdo extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/pdo/JitPdoExtensionHooksFacade.php}; Call
 * Pdo* files must not import {@code ext\pdo}.
 */
interface PdoExtensionHooks
{
    /** PDO::__construct() thin-AOT missing-driver honesty. */
    public function construct(Context $context, Variable ...$args): Value;

    /** PDO::getAvailableDrivers() thin-AOT. */
    public function getAvailableDrivers(Context $context, Variable ...$args): Value;
}

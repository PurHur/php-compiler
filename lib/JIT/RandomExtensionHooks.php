<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * random extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/random/JitRandomExtensionHooksFacade.php}; Call
 * Randomizer* files must not import {@code ext\random}.
 */
interface RandomExtensionHooks
{
    /** Random\Randomizer::__construct() user-script AOT. */
    public function randomizerConstruct(Context $context, Variable ...$args): Value;

    /** Random\Randomizer::getBytesFromString() user-script AOT. */
    public function randomizerGetBytesFromString(Context $context, Variable ...$args): Value;

    /** Random\Engine\Mt19937::__construct() user-script AOT. */
    public function mt19937Construct(Context $context, Variable ...$args): Value;
}

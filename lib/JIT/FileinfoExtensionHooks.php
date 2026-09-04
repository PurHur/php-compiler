<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * fileinfo extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/fileinfo/JitFileinfoExtensionHooksFacade.php}; Call
 * Finfo* files must not import {@code ext\fileinfo}.
 */
interface FileinfoExtensionHooks
{
    /** finfo::set_flags() / finfo_set_flags() thin-AOT. */
    public function setFlags(Context $context, bool $method, Variable ...$args): Value;

    /** finfo::buffer() user-script AOT. */
    public function bufferMethod(Context $context, Variable ...$args): Value;

    /** finfo::file() user-script AOT. */
    public function fileMethod(Context $context, Variable ...$args): Value;
}

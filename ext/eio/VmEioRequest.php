<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * EIO\Request opaque object — PECL eio request resource stand-in (#6442).
 */
final class VmEioRequest
{
    public const CLASS_LC = 'eio\\request';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry('EIO\\Request');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

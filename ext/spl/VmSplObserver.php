<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * SplObserver / SplSubject builtin interfaces (php-src ext/spl/spl_observer.c; issue #6778).
 *
 * Phase 0: registry + interface_exists parity; attach/notify semantics → #4768.
 */
final class VmSplObserver
{
    public static function register(Context $ctx): void
    {
        self::registerSplObserver($ctx);
        self::registerSplSubject($ctx);
    }

    private static function registerSplObserver(Context $ctx): void
    {
        if (isset($ctx->classes['splobserver'])) {
            return;
        }

        $entry = new ClassEntry('SplObserver');
        $entry->isInterface = true;
        $ctx->classes['splobserver'] = $entry;
    }

    private static function registerSplSubject(Context $ctx): void
    {
        if (isset($ctx->classes['splsubject'])) {
            return;
        }

        $entry = new ClassEntry('SplSubject');
        $entry->isInterface = true;
        $ctx->classes['splsubject'] = $entry;
    }
}

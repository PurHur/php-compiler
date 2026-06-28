<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\BuiltinClasses;
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
        $lc = 'splobserver';
        if (!isset($ctx->classes[$lc])) {
            $entry = new ClassEntry('SplObserver');
            $entry->isInterface = true;
            $ctx->classes[$lc] = $entry;
        }
        $entry = $ctx->classes[$lc];
        $entry->isInterface = true;
        if (!isset($entry->abstractMethods['update'])) {
            BuiltinClasses::registerBuiltinInterfaceMethods($entry, ['update']);
        }
    }

    private static function registerSplSubject(Context $ctx): void
    {
        $lc = 'splsubject';
        if (!isset($ctx->classes[$lc])) {
            $entry = new ClassEntry('SplSubject');
            $entry->isInterface = true;
            $ctx->classes[$lc] = $entry;
        }
        $entry = $ctx->classes[$lc];
        $entry->isInterface = true;
        if (!isset($entry->abstractMethods['attach'])) {
            BuiltinClasses::registerBuiltinInterfaceMethods($entry, ['attach', 'detach', 'notify']);
        }
    }
}

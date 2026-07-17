<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\VM\Context;

/**
 * Register PDO builtin classes (php-src ext/pdo/pdo.stub.php; #3367).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerExceptions($ctx);
        VmPDO::registerClass($ctx);
        VmPDOStatement::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesExceptionClass()) {
            return;
        }
        if (isset($ctx->classes['pdoexception'])) {
            return;
        }

        $entry = new \PHPCompiler\VM\ClassEntry('PDOException');
        if (isset($ctx->classes['runtimeexception'])) {
            $entry->parentLc = 'runtimeexception';
        } elseif (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }
        $ctx->classes['pdoexception'] = $entry;
    }
}

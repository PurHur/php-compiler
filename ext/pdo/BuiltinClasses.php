<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register PDO builtin classes (php-src ext/pdo/pdo.stub.php; #3367, #22455).
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
        VmPDORow::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
        if (isset($ctx->classes['pdoexception'])) {
            $ctx->classes['pdoexception']->isInternal = true;
        }
        if (isset($ctx->classes[VmPDORow::CLASS_LC])) {
            $ctx->classes[VmPDORow::CLASS_LC]->isInternal = true;
        }
    }

    /**
     * ThrowableManifest may already register PDOException; attach $errorInfo (#22455).
     * php-src: ext/pdo/pdo.stub.php — public ?array $errorInfo = null
     */
    private static function registerExceptions(Context $ctx): void
    {
        if (!PdoExtensionPolicy::advertisesExceptionClass()) {
            return;
        }
        if (!isset($ctx->classes['pdoexception'])) {
            $entry = new \PHPCompiler\VM\ClassEntry('PDOException');
            if (isset($ctx->classes['runtimeexception'])) {
                $entry->parentLc = 'runtimeexception';
            } elseif (isset($ctx->classes['exception'])) {
                $entry->parentLc = 'exception';
            }
            $ctx->classes['pdoexception'] = $entry;
        }

        self::patchPdoExceptionErrorInfo($ctx);
    }

    private static function patchPdoExceptionErrorInfo(Context $ctx): void
    {
        $entry = $ctx->classes['pdoexception'];
        foreach ($entry->properties as $prop) {
            if ('errorinfo' === \strtolower($prop->name)) {
                return;
            }
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $nullProto = new Variable(Variable::TYPE_NULL);
        $entry->properties[] = new ClassProperty(
            'errorInfo',
            null,
            $nullProto,
            false,
            $pub,
            'pdoexception'
        );
    }
}

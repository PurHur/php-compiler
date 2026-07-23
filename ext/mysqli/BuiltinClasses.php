<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/** Register mysqli + mysqli_result VM builtin classes (php-src ext/mysqli; #3435, #22456). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerAuxiliaryClasses($ctx);
        VmMysqli::registerClass($ctx);
        VmMysqliResult::registerClass($ctx);
        VmMysqliStmt::registerClass($ctx);
        VmMysqli::initStore($ctx);
    }

    private static function registerAuxiliaryClasses(Context $ctx): void
    {
        if (!isset($ctx->classes['mysqli_sql_exception'])) {
            $entry = new ClassEntry('mysqli_sql_exception');
            if (isset($ctx->classes['runtimeexception'])) {
                $entry->parentLc = 'runtimeexception';
            } elseif (isset($ctx->classes['exception'])) {
                $entry->parentLc = 'exception';
            }
            $entry->isInternal = true;
            $ctx->classes['mysqli_sql_exception'] = $entry;
        }

        self::patchMysqliSqlExceptionSqlState($ctx);

        if (!isset($ctx->classes['mysqli_warning'])) {
            $entry = new ClassEntry('mysqli_warning');
            if (isset($ctx->classes['exception'])) {
                $entry->parentLc = 'exception';
            }
            $entry->isInternal = true;
            $ctx->classes['mysqli_warning'] = $entry;
        }

        if (!isset($ctx->classes['mysqli_driver'])) {
            $entry = new ClassEntry('mysqli_driver');
            $entry->isInternal = true;
            $ctx->classes['mysqli_driver'] = $entry;
        }
    }

    /**
     * ThrowableManifest may already register mysqli_sql_exception; attach $sqlstate + getSqlState (#22456).
     * php-src: ext/mysqli/mysqli.stub.php — protected string $sqlstate = "00000"
     */
    private static function patchMysqliSqlExceptionSqlState(Context $ctx): void
    {
        $entry = $ctx->classes['mysqli_sql_exception'];
        $entry->isInternal = true;

        $hasSqlstate = false;
        foreach ($entry->properties as $prop) {
            if ('sqlstate' === \strtolower($prop->name)) {
                $hasSqlstate = true;
                break;
            }
        }
        if (!$hasSqlstate) {
            $prot = CfgFunc::FLAG_PROTECTED;
            $strProto = new Variable(Variable::TYPE_STRING);
            $default = new Variable(Variable::TYPE_STRING);
            $default->string('00000');
            $entry->properties[] = new ClassProperty(
                MysqliSqlExceptionGetSqlState::PROP_SQLSTATE,
                $default,
                $strProto,
                false,
                $prot,
                'mysqli_sql_exception'
            );
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        if (!isset($entry->methods['getsqlstate'])) {
            $entry->methods['getsqlstate'] = new MysqliSqlExceptionGetSqlState();
            $entry->methodVisibility['getsqlstate'] = $pub;
            $entry->methodNames['getsqlstate'] = 'getSqlState';
        }
    }
}

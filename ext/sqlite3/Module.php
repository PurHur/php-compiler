<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sqlite3 extension module entry (php-src ext/sqlite3/sqlite3.c; issue #7269, #3434, #17106).
 */
class Module extends ModuleAbstract
{
    /**
     * SQLite3 thin-AOT Call proxies + ClassConstFetch / NestedJIT stub props (#36204 / #35931).
     *
     * Context::defineBuiltins used to lookup+seed before modules registered; seeders must be
     * installed here then lookup re-run so props exist before user compile.
     */
    public function jitInit(JIT\Context $context): void
    {
        if (!CompilerVersion::supportsSqlite3()) {
            return;
        }

        $object = $context->type->object;
        $object->registerExternalClassSeeder('sqlite3', static function ($obj, int $id): void {
            $obj->seedExternalClassConstants($id, Sqlite3Constants::CLASS_CONSTANTS);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_ID, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_ROW, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_HAS, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_LAST_ROWID, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_CHANGES, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_ROW_COUNT, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_SUM, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_INT_PK, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_EXCEPTIONS, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::PROP_FOLD_ID, Variable::TYPE_NATIVE_LONG);
            $obj->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC;
            foreach ([
                '__construct', 'open', 'exec', 'query', 'prepare', 'querySingle',
                'close', 'lastInsertRowID', 'changes', 'lastErrorCode', 'lastErrorMsg',
                'busyTimeout', 'enableExceptions',
            ] as $method) {
                $obj->defineMethodVisibility($id, $method, $pub);
            }
            foreach (['escapeString', 'version'] as $method) {
                $obj->defineMethodVisibility($id, $method, $pubStatic);
            }
        });
        $object->registerExternalClassSeeder('sqlite3stmt', static function ($obj, int $id): void {
            $obj->defineProperty($id, Sqlite3JitSupport::STMT_PROP_SQL, Variable::TYPE_STRING);
            $obj->defineProperty($id, Sqlite3JitSupport::STMT_PROP_PARAM_COUNT, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::STMT_PROP_FOLD_ID, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::STMT_PROP_READONLY, Variable::TYPE_NATIVE_LONG);
            $obj->seedExternalClassConstants($id, Sqlite3Constants::STMT_CLASS_CONSTANTS);
            $obj->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['bindParam', 'bindValue', 'clear', 'close', 'execute', 'getSQL', 'paramCount', 'readOnly', 'reset'] as $method) {
                $obj->defineMethodVisibility($id, $method, $pub);
            }
        });
        $object->registerExternalClassSeeder('sqlite3result', static function ($obj, int $id): void {
            $obj->defineProperty($id, Sqlite3JitSupport::RESULT_PROP_ROW, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::RESULT_PROP_HAS, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::RESULT_PROP_CURSOR, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, Sqlite3JitSupport::RESULT_PROP_ROW_COUNT, Variable::TYPE_NATIVE_LONG);
            $obj->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['fetchArray', 'numColumns', 'columnName', 'columnType', 'reset', 'finalize'] as $method) {
                $obj->defineMethodVisibility($id, $method, $pub);
            }
        });

        // Apply seeders (defineBuiltins no longer looks these up).
        $object->lookup('SQLite3');
        $object->lookup('SQLite3Stmt');
        $object->lookup('SQLite3Result');

        foreach (['__construct', 'exec', 'querySingle', 'close', 'lastInsertRowID', 'changes', 'lastErrorCode', 'lastErrorMsg', 'busyTimeout', 'enableExceptions', 'escapeString', 'version', 'open', 'prepare', 'query'] as $sqliteMethod) {
            $context->functionProxies['sqlite3::'.strtolower($sqliteMethod)] = new JIT\Call\Sqlite3Method(
                $sqliteMethod
            );
        }
        foreach (['getSQL', 'paramCount', 'bindValue', 'bindParam', 'execute', 'readOnly'] as $stmtMethod) {
            $context->functionProxies['sqlite3stmt::'.strtolower($stmtMethod)] = new JIT\Call\Sqlite3StmtMethod(
                $stmtMethod
            );
        }
        foreach (['fetchArray', 'columnType'] as $resultMethod) {
            $context->functionProxies['sqlite3result::'.strtolower($resultMethod)] = new JIT\Call\Sqlite3ResultMethod(
                $resultMethod
            );
        }
    }

    public function init(Runtime $runtime): void
    {
        if (Sqlite3ExtensionPolicy::advertisesExceptionClass()) {
            require_once __DIR__.'/bootstrap_sqlite3exception.php';
        }
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return '3.45.1';
    }
}

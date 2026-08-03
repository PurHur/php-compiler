<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * pdo extension module entry (php-src ext/pdo/pdo.c; #3367).
 *
 * Also advertises logical {@code pdo_sqlite} when
 * {@see PdoExtensionPolicy::advertisesSqliteDriver()} (host pdo_sqlite or
 * PHP_COMPILER_ENABLE_PDO_SQLITE + libsqlite3; #24523), and {@code pdo_pgsql}
 * when {@see PdoExtensionPolicy::advertisesPgsqlDriver()} (host pdo_pgsql or
 * PHP_COMPILER_ENABLE_PDO_PGSQL + libpq; #26140).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        if (PdoExtensionPolicy::advertisesExceptionClass()) {
            require_once __DIR__.'/bootstrap_pdoexception.php';
        }
        // Host catch type for VmSqlite3Native errors — not userland class_exists (#24523).
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            require_once \dirname(__DIR__).'/sqlite3/bootstrap_sqlite3exception.php';
        }
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return '1.0.2';
    }

    public function getAdditionalExtensionNames(): array
    {
        $names = [];
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            $names[] = 'pdo_sqlite';
        }
        if (PdoExtensionPolicy::advertisesPgsqlDriver()) {
            $names[] = 'pdo_pgsql';
        }

        return $names;
    }

    public function getAdditionalExtensionVersions(): array
    {
        $versions = [];
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            $versions['pdo_sqlite'] = '1.0.2';
        }
        if (PdoExtensionPolicy::advertisesPgsqlDriver()) {
            $versions['pdo_pgsql'] = '1.0.2';
        }

        return $versions;
    }

    public function getFunctions(): array
    {
        return [
            new pdo_drivers(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * pdo extension module entry (php-src ext/pdo/pdo.c; #3367).
 *
 * Also advertises logical {@code pdo_sqlite} when libsqlite3 FFI is available.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        if (PdoExtensionPolicy::advertisesExceptionClass()) {
            require_once __DIR__.'/bootstrap_pdoexception.php';
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
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            return ['pdo_sqlite'];
        }

        return [];
    }

    public function getAdditionalExtensionVersions(): array
    {
        if (PdoExtensionPolicy::advertisesSqliteDriver()) {
            return ['pdo_sqlite' => '1.0.2'];
        }

        return [];
    }

    public function getFunctions(): array
    {
        return [
            new pdo_drivers(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/** mongodb extension module entry (PECL mongodb; #6575). */
class Module extends ModuleAbstract
{
    private const MONGODB_VERSION = '1.21.0';

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        require_once __DIR__.'/MongodbExtensionPolicy.php';
        require_once __DIR__.'/BuiltinClasses.php';
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionVersion(): string
    {
        return self::MONGODB_VERSION;
    }
}

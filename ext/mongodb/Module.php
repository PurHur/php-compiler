<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/** mongodb extension module entry (PECL mongodb; #6575, #27875). */
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

    public function getFunctions(): array
    {
        if (!MongodbExtensionPolicy::advertisesExtension()) {
            return [];
        }
        require_once __DIR__.'/VmMongodbTypes.php';
        require_once __DIR__.'/ns_bson_fromJSON.php';
        require_once __DIR__.'/ns_bson_toJSON.php';

        return [
            new ns_bson_fromJSON(),
            new ns_bson_toJSON(),
        ];
    }
}

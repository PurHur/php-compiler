<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * uri extension module entry (php-src ext/uri/; issue #9051).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/UriClassMethods.php';
        parent::init($runtime);
        if (!UriExtensionPolicy::advertisesExtension()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return UriExtensionPolicy::advertisesExtension() ? 'uri' : 'standard';
    }

    public function getAdditionalExtensionNames(): array
    {
        return [];
    }
}

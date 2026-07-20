<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * tidy extension module entry (php-src ext/tidy/tidy.c; #21464 / #3664).
 *
 * v1: register tidy_parse_string + tidy::cleanRepair; delegate to host ext/tidy when present.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/BuiltinClasses.php';
        require_once __DIR__.'/VmTidy.php';
        require_once __DIR__.'/tidy_parse_string.php';
        parent::init($runtime);
        if (!TidyExtensionPolicy::advertisesExtension()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return TidyExtensionPolicy::advertisesExtension() ? 'tidy' : 'standard';
    }

    public function getFunctions(): array
    {
        if (!TidyExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new tidy_parse_string(),
        ];
    }
}

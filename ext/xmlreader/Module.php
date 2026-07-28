<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * xmlreader extension module entry (php-src ext/xmlreader/php_xmlreader.c; issue #6135).
 *
 * PHP-in-PHP pull parser — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{

    /**
     * php-src ext/xmlreader builds on ext/libxml (libxml2).
     *
     * Runtime::loadCoreModules() already loads them in this order; declaring it makes the
     * constraint checkable instead of remembered (RELEASE-PLAN Phase 2.5).
     *
     * @return list<string>
     */
    public function getExtensionDependencies(): array
    {
        return ['libxml'];
    }
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [];
    }
}

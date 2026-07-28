<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * dom extension module entry (php-src ext/dom/php_dom.c; issue #6140).
 *
 * PHP-in-PHP DOM factory — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{

    /**
     * php-src ext/dom builds on ext/libxml (libxml2).
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
    /** php-src ext/dom/php_dom.h DOM_API_VERSION — libxml DOM module version (#15439). */
    private const DOM_API_VERSION = '20031129';

    public function getExtensionVersion(): string
    {
        return self::DOM_API_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        $fns = [
            new dom_import_simplexml(),
        ];
        // PHP 8.4 Dom\ living API (php-src php_dom.stub.php; #20711).
        // Class ns_import_simplexml — not Dom_import_* (case-collides with legacy).
        if (CompilerVersion::supportsDomLivingStandardNamespace()) {
            $fns[] = new ns_import_simplexml();
        }

        return $fns;
    }
}

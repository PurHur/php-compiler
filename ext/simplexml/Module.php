<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * simplexml extension module entry (php-src ext/simplexml/php_simplexml.c; #3338).
 *
 * PHP-in-PHP SimpleXMLElement tree — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{

    /**
     * php-src ext/simplexml builds on ext/libxml (libxml2).
     *
     * Runtime::loadCoreModules() already loads them in this order; declaring it makes the
     * constraint checkable instead of remembered (RELEASE-PLAN Phase 2.5).
     *
     * @return list<string>
     */
    public function jitInit(JIT\Context $context): void
    {
        // Thin AOT instanceof Traversable / method visibility (#35831 / #36204).
        $seed = static function ($obj, int $id, string $lcname): void {
            $obj->seedSimpleXmlElementAotInterfaces($id, $lcname);
        };
        $context->type->object->registerExternalClassSeeder(
            'simplexmlelement',
            static function ($obj, int $id) use ($seed): void {
                $seed($obj, $id, 'simplexmlelement');
            }
        );
        $context->type->object->registerExternalClassSeeder(
            'simplexmliterator',
            static function ($obj, int $id) use ($seed): void {
                $seed($obj, $id, 'simplexmliterator');
            }
        );
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [
            new simplexml_load_string(),
            new simplexml_load_file(),
            new simplexml_import_dom(),
        ];
    }
}

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

        // User-script AOT dim/prop/xpath folds — lib/JIT.php must not import this class (#36204).
        $hooks = $context->extensionLowering;
        $hooks->prepareDimWriteHook = static function ($ctx, $container, $dim) {
            return JitSimpleXmlUserScript::tryPrepareDimWrite($ctx, $container, $dim);
        };
        $hooks->offsetGetHook = static function ($ctx, $container, $dim) {
            return JitSimpleXmlUserScript::tryOffsetGet($ctx, $container, $dim);
        };
        $hooks->foldXpathListDimHook = static function ($ctx, $container, $dim) {
            return JitSimpleXmlUserScript::tryFoldXpathListDim($ctx, $container, $dim);
        };
        $hooks->propertyGetHook = static function ($ctx, $receiver, $name) {
            return JitSimpleXmlUserScript::tryGet($ctx, $receiver, $name);
        };
        $hooks->isTrackedSimpleXmlReceiverHook = static function ($receiver): bool {
            return JitSimpleXmlUserScript::isTrackedReceiver($receiver);
        };
        $hooks->applyPendingXpathAssignHook = static function ($result): void {
            JitSimpleXmlUserScript::applyPendingXpathAssign($result);
        };
        $hooks->applyPendingElementAssignHook = static function ($result): bool {
            return JitSimpleXmlUserScript::applyPendingElementAssign($result);
        };
        $hooks->applyPendingIteratorToArrayHostArrayHook = static function ($result): bool {
            return JitSimpleXmlUserScript::applyPendingIteratorToArrayHostArray($result);
        };
        $hooks->propertySetHook = static function ($ctx, $container, string $propName, $value) {
            return JitSimpleXmlUserScript::tryPropSet($ctx, $container, $propName, $value);
        };
        $hooks->offsetSetHook = static function ($ctx, $receiver, $key, $value) {
            return JitSimpleXmlUserScript::tryOffsetSet($ctx, $receiver, $key, $value);
        };
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        // empty($sxe->child) — lib/VM must not import ext\simplexml (#36204 / php-src sxe.c).
        // isManaged stays false: child names are dynamic, not ClassProperty slots.
        \PHPCompiler\VM\ObjectComputedPropertySupport::register(
            new \PHPCompiler\VM\ObjectComputedPropertyHandler(
                static fn (\PHPCompiler\VM\ObjectEntry $_, string $__): bool => false,
                null,
                null,
                static function (\PHPCompiler\VM\ObjectEntry $o, string $n): ?bool {
                    if (VmSimpleXml::CLASS_LC !== strtolower($o->class->name)
                        || !SimpleXmlRegistry::has($o)
                    ) {
                        return null;
                    }

                    return VmSimpleXml::childPropertyIsEmpty($o, $n);
                }
            )
        );
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

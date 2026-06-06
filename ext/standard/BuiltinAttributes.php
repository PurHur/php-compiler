<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\Builtin\DeprecatedConstruct;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register ext/standard builtin attribute classes (php-src Zend/zend_attributes.stub.php; #7145).
 *
 * Attribute / ReturnTypeWillChange / AllowDynamicProperties remain in lib/VM/AttributeSupport
 * until follow-up issues migrate them here.
 */
final class BuiltinAttributes
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerDeprecated($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerDeprecated(Context $ctx): void
    {
        if (!isset($ctx->classes[AttributeSupport::CLASS_ATTRIBUTE])) {
            return;
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('Deprecated');
        $entry->parentLc = AttributeSupport::CLASS_ATTRIBUTE;
        $entry->properties[] = new ClassProperty('message', null, $strProto, true);
        $entry->properties[] = new ClassProperty('since', null, $strProto, true);
        $entry->constructor = new DeprecatedConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $targets = AttributeSupport::TARGET_METHOD
            | AttributeSupport::TARGET_FUNCTION
            | AttributeSupport::TARGET_CLASS_CONSTANT;
        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => $targets]]),
        ];

        $ctx->classes[AttributeSupport::CLASS_DEPRECATED] = $entry;
    }
}

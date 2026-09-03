<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Variable;
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
    /**
     * XMLReader thin-AOT cursor layout + node-type class constants (#27299 / #35983 / #36204).
     *
     * php-src: ext/xmlreader/php_xmlreader.stub.php — props must exist before allocate().
     */
    public function jitInit(JIT\Context $context): void
    {
        $context->type->object->registerExternalClassSeeder('xmlreader', static function ($obj, int $id): void {
            $obj->defineProperty($id, JitXmlReaderUserScript::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, 'nodeType', Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, 'name', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'value', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'depth', Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, 'localName', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'prefix', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'namespaceURI', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'attributeCount', Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, 'hasAttributes', Variable::TYPE_NATIVE_BOOL);
            $obj->defineProperty($id, 'hasValue', Variable::TYPE_NATIVE_BOOL);
            $obj->defineProperty($id, 'isEmptyElement', Variable::TYPE_NATIVE_BOOL);
            $obj->defineProperty($id, 'isDefault', Variable::TYPE_NATIVE_BOOL);
            $obj->defineProperty($id, 'xmlLang', Variable::TYPE_STRING);
            $obj->defineProperty($id, 'baseURI', Variable::TYPE_STRING);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;
            foreach (['read', 'close', 'next'] as $method) {
                $obj->defineMethodVisibility($id, $method, $pub);
            }
            foreach (['open', 'xml', 'fromstring', 'fromuri', 'fromstream'] as $method) {
                $obj->defineMethodVisibility($id, $method, $pubStatic);
            }
            $obj->seedExternalClassConstants($id, [
                'none' => XmlReaderConstants::NONE,
                'element' => XmlReaderConstants::ELEMENT,
                'attribute' => XmlReaderConstants::ATTRIBUTE,
                'text' => XmlReaderConstants::TEXT,
                'cdata' => XmlReaderConstants::CDATA,
                'entity_ref' => XmlReaderConstants::ENTITY_REF,
                'entity' => XmlReaderConstants::ENTITY,
                'pi' => XmlReaderConstants::PI,
                'comment' => XmlReaderConstants::COMMENT,
                'doc' => XmlReaderConstants::DOC,
                'doc_type' => XmlReaderConstants::DOC_TYPE,
                'doc_fragment' => XmlReaderConstants::DOC_FRAGMENT,
                'notation' => XmlReaderConstants::NOTATION,
                'whitespace' => XmlReaderConstants::WHITESPACE,
                'significant_whitespace' => XmlReaderConstants::SIGNIFICANT_WHITESPACE,
                'end_element' => XmlReaderConstants::END_ELEMENT,
                'end_entity' => XmlReaderConstants::END_ENTITY,
                'xml_declaration' => XmlReaderConstants::XML_DECLARATION,
            ]);
        });
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

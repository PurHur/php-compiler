<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\libxml\VmLibxml;
use PHPCompiler\ext\spl\InternalIteratorBuiltin;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableObject;

/**
 * DOM factory + serialization in PHP (php-src ext/dom/php_dom.c; issue #6140).
 *
 * PHP-in-PHP: no runtime/*.c growth — tree state in {@see DomRegistry}.
 */
final class VmDom
{
    private static ?ClassEntry $implementationClassEntry = null;

    /** @var array<int, ObjectEntry> */
    private static array $implementationSingletons = [];
    public const CLASS_IMPLEMENTATION = 'domimplementation';

    public const CLASS_DOCUMENT = 'domdocument';

    public const CLASS_DOCUMENT_TYPE = 'domdocumenttype';

    public const CLASS_PROCESSING_INSTRUCTION = 'domprocessinginstruction';

    public const CLASS_ELEMENT = 'domelement';

    public const CLASS_TEXT = 'domtext';

    public const CLASS_CDATA = 'domcdatasection';

    public const CLASS_CHARACTER_DATA = 'domcharacterdata';

    public const CLASS_COMMENT = 'domcomment';

    public const CLASS_ATTR = 'domattr';

    /** php-src DOMNameSpaceNode — not a DOMNode subclass (#20097). */
    public const CLASS_NAMESPACE_NODE = 'domnamespacenode';

    public const CLASS_ENTITY_REFERENCE = 'domentityreference';

    public const CLASS_ENTITY = 'domentity';

    public const CLASS_NOTATION = 'domnotation';

    public const CLASS_DOCUMENT_FRAGMENT = 'domdocumentfragment';

    public const CLASS_NODE = 'domnode';

    public const CLASS_NODE_LIST = 'domnodelist';

    public const CLASS_NAMED_NODE_MAP = 'domnamednodemap';

    public const CLASS_TOKEN_LIST = 'domtokenlist';

    public const CLASS_XPATH = 'domxpath';

    /** php-src ext/dom/php_dom.stub.php — DOMXPath::$registerNodeNamespaces (#20842). */
    public const PROP_REGISTER_NODE_NAMESPACES = 'registerNodeNamespaces';

    /** php-src ext/dom/php_dom.stub.php — DOMXPath::$document (#20842). */
    public const PROP_XPATH_DOCUMENT = 'document';

    public const PROP_FORMAT_OUTPUT = 'formatOutput';

    public const PROP_IMPLEMENTATION = 'implementation';

    public const PROP_VALIDATE_ON_PARSE = 'validateOnParse';

    public const PROP_RESOLVE_EXTERNALS = 'resolveExternals';

    public const PROP_SUBSTITUTE_ENTITIES = 'substituteEntities';

    public const PROP_PRESERVE_WHITE_SPACE = 'preserveWhiteSpace';

    public const PROP_RECOVER = 'recover';

    public const PROP_STRICT_ERROR_CHECKING = 'strictErrorChecking';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public const PROP_DOCTYPE = 'doctype';

    public const PROP_ENCODING = 'encoding';

    public const PROP_XML_ENCODING = 'xmlEncoding';

    /** Legacy DOM Level-3 alias of {@see PROP_XML_ENCODING}/encoding (php-src document.c; #28587). */
    public const PROP_ACTUAL_ENCODING = 'actualEncoding';

    public const PROP_XML_VERSION = 'xmlVersion';

    /** Legacy DOM Level-3 alias of {@see PROP_XML_VERSION} (php-src document.c; #28587). */
    public const PROP_VERSION = 'version';

    public const PROP_XML_STANDALONE = 'xmlStandalone';

    /** Legacy DOM Level-3 alias of {@see PROP_XML_STANDALONE} (php-src document.c; #28587). */
    public const PROP_STANDALONE = 'standalone';

    /** Legacy DOM Level-3 stub — always null / read-only (php-src document.c; #28587). */
    public const PROP_CONFIG = 'config';

    public const PROP_DOCUMENT_URI = 'documentURI';

    public const PROP_NODE_NAME = 'nodeName';

    public const PROP_TAG_NAME = 'tagName';

    public const PROP_NODE_TYPE = 'nodeType';

    public const PROP_OWNER_DOCUMENT = 'ownerDocument';

    public const PROP_NODE_VALUE = 'nodeValue';

    public const PROP_TEXT_CONTENT = 'textContent';

    public const PROP_BASE_URI = 'baseURI';

    public const PROP_NAMESPACE_URI = 'namespaceURI';

    public const PROP_LOCAL_NAME = 'localName';

    public const PROP_PREFIX = 'prefix';

    public const PROP_PREVIOUS_SIBLING = 'previousSibling';

    public const PROP_FIRST_CHILD = 'firstChild';

    public const PROP_LAST_CHILD = 'lastChild';

    public const PROP_CHILD_NODES = 'childNodes';

    public const PROP_ATTRIBUTES = 'attributes';

    /** php-src php_dom.stub.php — DOMElement/DOMAttr::$schemaTypeInfo always null (#31753). */
    public const PROP_SCHEMA_TYPE_INFO = 'schemaTypeInfo';

    public const PROP_CLASS_LIST = 'classList';

    public const PROP_NEXT_SIBLING = 'nextSibling';

    public const PROP_PARENT_NODE = 'parentNode';

    /** PHP 8.3+ DOMNode::$isConnected (ext/dom/node.c; #19653). */
    public const PROP_IS_CONNECTED = 'isConnected';

    public const PROP_PARENT_ELEMENT = 'parentElement';

    /** ParentNode first element child (php-src ext/dom/parentnode.c; #19431). */
    public const PROP_FIRST_ELEMENT_CHILD = 'firstElementChild';

    /** ParentNode last element child (php-src ext/dom/parentnode.c; #19431). */
    public const PROP_LAST_ELEMENT_CHILD = 'lastElementChild';

    /** ParentNode element-only child count (php-src ext/dom/parentnode.c; #19431). */
    public const PROP_CHILD_ELEMENT_COUNT = 'childElementCount';

    /**
     * Dom\* ParentNode::$children — live Dom\HTMLCollection of element children
     * (php-src PHP-8.5+ php_dom.stub.php; gated by {@see CompilerVersion::supportsDomParentNodeChildren()}; #21559).
     */
    public const PROP_CHILDREN = 'children';

    /** NonDocumentTypeChildNode next element sibling (php-src ext/dom/nodelist.c; #19431). */
    public const PROP_NEXT_ELEMENT_SIBLING = 'nextElementSibling';

    /** NonDocumentTypeChildNode previous element sibling (php-src ext/dom/nodelist.c; #19431). */
    public const PROP_PREVIOUS_ELEMENT_SIBLING = 'previousElementSibling';

    public const PROP_LENGTH = 'length';

    public const PROP_NAME = 'name';

    public const PROP_VALUE = 'value';

    public const PROP_DATA = 'data';

    public const PROP_WHOLE_TEXT = 'wholeText';

    public const PROP_OWNER_ELEMENT = 'ownerElement';

    /** DOMAttr::$specified — always true (php-src ext/dom/attr.c dom_attr_specified_read; #20605). */
    public const PROP_SPECIFIED = 'specified';

    public const PROP_PUBLIC_ID = 'publicId';

    public const PROP_SYSTEM_ID = 'systemId';

    /** DOMDocumentType::$internalSubset — libxml intSubset dump or null (php-src documenttype.c; #21000). */
    public const PROP_INTERNAL_SUBSET = 'internalSubset';

    public const PROP_ENTITIES = 'entities';

    public const PROP_NOTATIONS = 'notations';

    public const PROP_NOTATION_NAME = 'notationName';

    public const PROP_TARGET = 'target';

    /** JIT/AOT: string id → DOMElement map mirrored from DomRegistry::elementIds (#17954). */
    public const PROP_ELEMENT_ID_MAP = '__phpcDomElementIdMap';

    /** JIT/AOT: DomRegistry object id for scalar helper bridges (#17954, #16075). */
    public const PROP_REGISTRY_ID = '__phpcDomRegistryId';

    /**
     * User-script AOT: serialized child markup for saveXML($node) after ParentNode
     * append/prepend (#26765). Distinct from textContent (descendant text only).
     */
    public const PROP_USER_SCRIPT_INNER_XML = '__phpcUserScriptInnerXml';

    /**
     * User-script AOT: ` xmlns:prefix="uri"` / ` xmlns="uri"` for saveXML($node)
     * after createElementNS (libxml xmlNodeDump nsDef; #32302).
     */
    public const PROP_USER_SCRIPT_XMLNS_ATTR = '__phpcUserScriptXmlnsAttr';

    /**
     * Thin-AOT childNodes NodeList → owning node, for item() walks without DomRegistry (#27410).
     */
    public const PROP_CHILD_NODES_OWNER = '__phpcChildNodesOwner';

    /**
     * php-src stub property for Reflection (XMLReader #31639 pattern; #31753).
     *
     * Runtime reads for computed node props stay virtual via {@see DomNodePropertySupport}.
     */
    private static function stubDeclaredProperty(
        string $name,
        string $typeLabel,
        string $declaringClassLc,
        int $visibility,
        bool $readonly = true,
        ?Variable $default = null
    ): ClassProperty {
        $proto = new Variable(Variable::TYPE_UNDEFINED);
        $proto->declaredTypeLabel = $typeLabel;

        return new ClassProperty($name, $default, $proto, $readonly, $visibility, $declaringClassLc);
    }

    public static function registerClasses(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_IMPLEMENTATION])) {
            return;
        }

        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $strProto = new Variable(Variable::TYPE_STRING);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $objProto = new Variable(Variable::TYPE_OBJECT);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $pub = CfgFunc::FLAG_PUBLIC;

        $node = new ClassEntry('DOMNode');
        $node->isInternal = true;
        $node->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $node->properties[] = new ClassProperty(self::PROP_NODE_TYPE, null, $intProto);
        $node->properties[] = new ClassProperty(self::PROP_OWNER_DOCUMENT, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_NODE_VALUE, $nullProto, $strProto);
        $node->properties[] = new ClassProperty(self::PROP_TEXT_CONTENT, $nullProto, $strProto);
        $node->properties[] = new ClassProperty(self::PROP_FIRST_CHILD, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_LAST_CHILD, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_CHILD_NODES, null, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_PREVIOUS_SIBLING, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_NEXT_SIBLING, $nullProto, $objProto);
        $node->properties[] = new ClassProperty(self::PROP_PARENT_NODE, $nullProto, $objProto);
        if (CompilerVersion::supportsDomNodeIsConnected()) {
            $node->properties[] = new ClassProperty(self::PROP_IS_CONNECTED, null, $boolProto);
        }
        if (CompilerVersion::supportsDomParentElement()) {
            $node->properties[] = new ClassProperty(self::PROP_PARENT_ELEMENT, $nullProto, $objProto);
        }
        // php-src php_dom.stub.php — declared on DOMNode, inherited by all node classes (#31753).
        $node->properties[] = self::stubDeclaredProperty(
            self::PROP_ATTRIBUTES,
            '?DOMNamedNodeMap',
            self::CLASS_NODE,
            $pub,
            true,
            $nullProto
        );
        $node->properties[] = self::stubDeclaredProperty(
            self::PROP_NAMESPACE_URI,
            '?string',
            self::CLASS_NODE,
            $pub,
            true,
            $nullProto
        );
        $node->properties[] = self::stubDeclaredProperty(
            self::PROP_PREFIX,
            'string',
            self::CLASS_NODE,
            $pub,
            false
        );
        $node->properties[] = self::stubDeclaredProperty(
            self::PROP_LOCAL_NAME,
            '?string',
            self::CLASS_NODE,
            $pub,
            true
        );
        $node->properties[] = self::stubDeclaredProperty(
            self::PROP_BASE_URI,
            '?string',
            self::CLASS_NODE,
            $pub,
            true,
            $nullProto
        );
        // Engine DomRegistry id — C-only storage; not in Zend PHP property table (#31439, #22513).
        $registryIdProp = new ClassProperty(self::PROP_REGISTRY_ID, null, $intProto);
        $registryIdProp->phpInvisible = true;
        $node->properties[] = $registryIdProp;
        $node->methods['clonenode'] = new NodeCloneNode();
        $node->methodVisibility['clonenode'] = $pub;
        $node->methods['appendchild'] = new NodeAppendChild();
        $node->methodVisibility['appendchild'] = $pub;
        $node->methods['replacechild'] = new NodeReplaceChild();
        $node->methodVisibility['replacechild'] = $pub;
        $node->methods['insertbefore'] = new NodeInsertBefore();
        $node->methodVisibility['insertbefore'] = $pub;
        $node->methods['removechild'] = new NodeRemoveChild();
        $node->methodVisibility['removechild'] = $pub;
        $node->methods['issamenode'] = new NodeIsSameNode();
        $node->methodVisibility['issamenode'] = $pub;
        if (CompilerVersion::supportsDomNodeIsEqualNode()) {
            $node->methods['isequalnode'] = new NodeIsEqualNode();
            $node->methodVisibility['isequalnode'] = $pub;
        }
        $node->methods['haschildnodes'] = new NodeHasChildNodes();
        $node->methodVisibility['haschildnodes'] = $pub;
        if (CompilerVersion::supportsDomNodeContains()) {
            $node->methods['contains'] = new NodeContains();
            $node->methodVisibility['contains'] = $pub;
        }
        if (CompilerVersion::supportsDomNodeGetRootNode()) {
            $node->methods['getrootnode'] = new NodeGetRootNode();
            $node->methodVisibility['getrootnode'] = $pub;
        }
        // ParentNode / ChildNode mutators are NOT on DOMNode — php_dom.stub.php (#23155).
        $node->methods['lookupprefix'] = new NodeLookupPrefix();
        $node->methodVisibility['lookupprefix'] = $pub;
        $node->methods['lookupnamespaceuri'] = new NodeLookupNamespaceURI();
        $node->methodVisibility['lookupnamespaceuri'] = $pub;
        $node->methods['getlineno'] = new NodeGetLineNo();
        $node->methodVisibility['getlineno'] = $pub;
        $node->methods['getnodepath'] = new NodeGetNodePath();
        $node->methodVisibility['getnodepath'] = $pub;
        $node->methods['hasattributes'] = new NodeHasAttributes();
        $node->methodVisibility['hasattributes'] = $pub;
        $node->methods['isdefaultnamespace'] = new NodeIsDefaultNamespace();
        $node->methodVisibility['isdefaultnamespace'] = $pub;
        $node->methods['issupported'] = new NodeIsSupported();
        $node->methodVisibility['issupported'] = $pub;
        if (CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
            $node->methods['comparedocumentposition'] = new NodeCompareDocumentPosition();
            $node->methodVisibility['comparedocumentposition'] = $pub;
            DomClassConstants::registerIntConstants($node, [
                'DOCUMENT_POSITION_DISCONNECTED' => DomConstants::DOCUMENT_POSITION_DISCONNECTED,
                'DOCUMENT_POSITION_PRECEDING' => DomConstants::DOCUMENT_POSITION_PRECEDING,
                'DOCUMENT_POSITION_FOLLOWING' => DomConstants::DOCUMENT_POSITION_FOLLOWING,
                'DOCUMENT_POSITION_CONTAINS' => DomConstants::DOCUMENT_POSITION_CONTAINS,
                'DOCUMENT_POSITION_CONTAINED_BY' => DomConstants::DOCUMENT_POSITION_CONTAINED_BY,
                'DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC' => DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC,
            ]);
        }
        $node->methods['normalize'] = new NodeNormalize();
        $node->methodVisibility['normalize'] = $pub;
        $node->methods['c14n'] = new NodeC14N();
        $node->methodVisibility['c14n'] = $pub;
        $node->methodNames['c14n'] = 'C14N';
        $node->methods['c14nfile'] = new NodeC14NFile();
        $node->methodVisibility['c14nfile'] = $pub;
        $node->methodNames['c14nfile'] = 'C14NFile';
        // php-src ext/dom/node.c — DOMNode::__sleep/__wakeup subclass-serializable deny (#23073, GH-8996).
        $node->methods['__sleep'] = new NodeSleep();
        $node->methodVisibility['__sleep'] = $pub;
        $node->methods['__wakeup'] = new NodeWakeup();
        $node->methodVisibility['__wakeup'] = $pub;
        $ctx->classes[self::CLASS_NODE] = $node;

        $text = new ClassEntry('DOMText');
        $text->isInternal = true;
        $text->parentLc = self::CLASS_CHARACTER_DATA;
        $text->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $text->properties[] = new ClassProperty(self::PROP_WHOLE_TEXT, null, $strProto);
        // php-src ext/dom/text.c — DOMText::__construct orphaned text (#24631).
        $textConstruct = new TextConstruct();
        $text->constructor = $textConstruct;
        $text->methods['__construct'] = $textConstruct;
        $text->methodVisibility['__construct'] = $pub;
        $text->methods['splittext'] = new TextSplitText();
        $text->methodVisibility['splittext'] = $pub;
        $text->methodNames['splittext'] = 'splitText';
        $text->methods['iswhitespaceinelementcontent'] = new TextIsWhitespaceInElementContent();
        $text->methodVisibility['iswhitespaceinelementcontent'] = $pub;
        $text->methodNames['iswhitespaceinelementcontent'] = 'isWhitespaceInElementContent';
        $text->methods['iselementcontentwhitespace'] = new TextIsElementContentWhitespace();
        $text->methodVisibility['iselementcontentwhitespace'] = $pub;
        $text->methodNames['iselementcontentwhitespace'] = 'isElementContentWhitespace';
        $ctx->classes[self::CLASS_TEXT] = $text;

        $cdata = new ClassEntry('DOMCdataSection');
        $cdata->isInternal = true;
        $cdata->parentLc = self::CLASS_TEXT;
        $cdata->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        // php-src ext/dom/cdatasection.c — DOMCdataSection::__construct (#24631).
        $cdataConstruct = new CdataSectionConstruct();
        $cdata->constructor = $cdataConstruct;
        $cdata->methods['__construct'] = $cdataConstruct;
        $cdata->methodVisibility['__construct'] = $pub;
        $ctx->classes[self::CLASS_CDATA] = $cdata;

        $characterData = new ClassEntry('DOMCharacterData');
        $characterData->isInternal = true;
        $characterData->parentLc = self::CLASS_NODE;
        // php-src php_dom.stub.php — DOMCharacterData implements DOMChildNode (#22389).
        $characterData->interfaces[] = 'domchildnode';
        $characterData->properties[] = new ClassProperty(self::PROP_DATA, null, $strProto);
        $characterData->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
        // NonDocumentTypeChildNode on CharacterData (Text/Comment/CDATA; #19431).
        $characterData->properties[] = new ClassProperty(self::PROP_NEXT_ELEMENT_SIBLING, $nullProto, $objProto);
        $characterData->properties[] = new ClassProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING, $nullProto, $objProto);
        $characterData->methods['appenddata'] = new CharacterDataAppendData();
        $characterData->methodVisibility['appenddata'] = $pub;
        $characterData->methodNames['appenddata'] = 'appendData';
        $characterData->methods['deletedata'] = new CharacterDataDeleteData();
        $characterData->methodVisibility['deletedata'] = $pub;
        $characterData->methodNames['deletedata'] = 'deleteData';
        $characterData->methods['insertdata'] = new CharacterDataInsertData();
        $characterData->methodVisibility['insertdata'] = $pub;
        $characterData->methodNames['insertdata'] = 'insertData';
        $characterData->methods['replacedata'] = new CharacterDataReplaceData();
        $characterData->methodVisibility['replacedata'] = $pub;
        $characterData->methodNames['replacedata'] = 'replaceData';
        $characterData->methods['substringdata'] = new CharacterDataSubstringData();
        $characterData->methodVisibility['substringdata'] = $pub;
        $characterData->methodNames['substringdata'] = 'substringData';
        // DOMChildNode — Element + CharacterData only (php_dom.stub.php; #23155).
        self::registerChildNodeMutationMethods($characterData, $pub);
        $ctx->classes[self::CLASS_CHARACTER_DATA] = $characterData;

        $comment = new ClassEntry('DOMComment');
        $comment->isInternal = true;
        $comment->parentLc = self::CLASS_CHARACTER_DATA;
        $comment->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        // php-src ext/dom/comment.c — DOMComment::__construct orphaned comment (#24631).
        $commentConstruct = new CommentConstruct();
        $comment->constructor = $commentConstruct;
        $comment->methods['__construct'] = $commentConstruct;
        $comment->methodVisibility['__construct'] = $pub;
        $ctx->classes[self::CLASS_COMMENT] = $comment;

        $entityRef = new ClassEntry('DOMEntityReference');
        $entityRef->isInternal = true;
        $entityRef->parentLc = self::CLASS_NODE;
        $entityRef->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        // php-src ext/dom/entityreference.c — DOMEntityReference::__construct (#24631).
        $entityRefConstruct = new EntityReferenceConstruct();
        $entityRef->constructor = $entityRefConstruct;
        $entityRef->methods['__construct'] = $entityRefConstruct;
        $entityRef->methodVisibility['__construct'] = $pub;
        $ctx->classes[self::CLASS_ENTITY_REFERENCE] = $entityRef;

        $entity = new ClassEntry('DOMEntity');
        $entity->isInternal = true;
        $entity->parentLc = self::CLASS_NODE;
        $entity->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $entity->properties[] = new ClassProperty(self::PROP_PUBLIC_ID, $nullProto, $strProto);
        $entity->properties[] = new ClassProperty(self::PROP_SYSTEM_ID, $nullProto, $strProto);
        $entity->properties[] = new ClassProperty(self::PROP_NOTATION_NAME, $nullProto, $strProto);
        // php-src php_dom.stub.php — deprecated always-null (#31753).
        $entity->properties[] = self::stubDeclaredProperty(
            self::PROP_ACTUAL_ENCODING,
            '?string',
            self::CLASS_ENTITY,
            $pub,
            true,
            $nullProto
        );
        $entity->properties[] = self::stubDeclaredProperty(
            self::PROP_ENCODING,
            '?string',
            self::CLASS_ENTITY,
            $pub,
            true,
            $nullProto
        );
        $entity->properties[] = self::stubDeclaredProperty(
            self::PROP_VERSION,
            '?string',
            self::CLASS_ENTITY,
            $pub,
            true,
            $nullProto
        );
        $ctx->classes[self::CLASS_ENTITY] = $entity;

        $notation = new ClassEntry('DOMNotation');
        $notation->isInternal = true;
        $notation->parentLc = self::CLASS_NODE;
        $notation->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $notation->properties[] = new ClassProperty(self::PROP_PUBLIC_ID, $nullProto, $strProto);
        $notation->properties[] = new ClassProperty(self::PROP_SYSTEM_ID, $nullProto, $strProto);
        $ctx->classes[self::CLASS_NOTATION] = $notation;

        $attr = new ClassEntry('DOMAttr');
        $attr->isInternal = true;
        $attr->parentLc = self::CLASS_NODE;
        $attr->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(self::PROP_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(self::PROP_VALUE, null, $strProto);
        $attr->properties[] = new ClassProperty(self::PROP_OWNER_ELEMENT, $nullProto, $objProto);
        // php-src: "useless; always returns true" (#20605, ext/dom/attr.c).
        $specifiedDefault = new Variable(Variable::TYPE_BOOLEAN);
        $specifiedDefault->bool(true);
        $attr->properties[] = new ClassProperty(self::PROP_SPECIFIED, $specifiedDefault, $boolProto);
        // php-src php_dom.stub.php — mixed schemaTypeInfo always null (#31753).
        $attr->properties[] = self::stubDeclaredProperty(
            self::PROP_SCHEMA_TYPE_INFO,
            'mixed',
            self::CLASS_ATTR,
            $pub,
            true,
            $nullProto
        );
        // php-src ext/dom/attr.c — DOMAttr::__construct orphaned attribute (#24631).
        $attrConstruct = new AttrConstruct();
        $attr->constructor = $attrConstruct;
        $attr->methods['__construct'] = $attrConstruct;
        $attr->methodVisibility['__construct'] = $pub;
        $attr->methods['isid'] = new AttrIsId();
        $attr->methodVisibility['isid'] = $pub;
        $attr->methodNames['isid'] = 'isId';
        $ctx->classes[self::CLASS_ATTR] = $attr;

        // DOMNameSpaceNode — standalone (no DOMNode parent); php-src php_dom.stub.php (#20097).
        $nsNode = new ClassEntry('DOMNameSpaceNode');
        $nsNode->isInternal = true;
        $nsNode->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_NODE_VALUE, $nullProto, $strProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_NODE_TYPE, null, $intProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_PREFIX, null, $strProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_LOCAL_NAME, null, $strProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_NAMESPACE_URI, $nullProto, $strProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_OWNER_DOCUMENT, $nullProto, $objProto);
        $nsNode->properties[] = new ClassProperty(self::PROP_PARENT_NODE, $nullProto, $objProto);
        // php-src php_dom.stub.php — @implementation-alias DOMNode::__sleep/__wakeup (#23073).
        $nsNode->methods['__sleep'] = new NodeSleep();
        $nsNode->methodVisibility['__sleep'] = $pub;
        $nsNode->methods['__wakeup'] = new NodeWakeup();
        $nsNode->methodVisibility['__wakeup'] = $pub;
        $ctx->classes[self::CLASS_NAMESPACE_NODE] = $nsNode;

        // IteratorAggregate + Countable (php-src php_dom.stub.php; #21298) — not Iterator.
        $nodeList = new ClassEntry('DOMNodeList');
        $nodeList->isInternal = true;
        $nodeList->interfaces[] = 'countable';
        if (isset($ctx->classes['iteratoraggregate'])) {
            $nodeList->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['traversable'])) {
            $nodeList->interfaces[] = 'traversable';
        }
        $nodeList->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
        $nodeList->methods['item'] = new NodeListItem();
        $nodeList->methodVisibility['item'] = $pub;
        $nodeList->methods['count'] = new NodeListCount();
        $nodeList->methodVisibility['count'] = $pub;
        $nodeList->methods['getiterator'] = new NodeListGetIterator();
        $nodeList->methodVisibility['getiterator'] = $pub;
        $nodeList->methodNames['getiterator'] = 'getIterator';
        $ctx->classes[self::CLASS_NODE_LIST] = $nodeList;

        $namedNodeMap = new ClassEntry('DOMNamedNodeMap');
        $namedNodeMap->isInternal = true;
        $namedNodeMap->interfaces[] = 'countable';
        if (isset($ctx->classes['iteratoraggregate'])) {
            $namedNodeMap->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['traversable'])) {
            $namedNodeMap->interfaces[] = 'traversable';
        }
        $namedNodeMap->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
        $namedNodeMap->methods['item'] = new NamedNodeMapItem();
        $namedNodeMap->methodVisibility['item'] = $pub;
        $namedNodeMap->methods['getnameditem'] = new NamedNodeMapGetNamedItem();
        $namedNodeMap->methodVisibility['getnameditem'] = $pub;
        $namedNodeMap->methodNames['getnameditem'] = 'getNamedItem';
        $namedNodeMap->methods['getnameditemns'] = new NamedNodeMapGetNamedItemNS();
        $namedNodeMap->methodVisibility['getnameditemns'] = $pub;
        $namedNodeMap->methodNames['getnameditemns'] = 'getNamedItemNS';
        $namedNodeMap->methods['count'] = new NamedNodeMapCount();
        $namedNodeMap->methodVisibility['count'] = $pub;
        $namedNodeMap->methods['getiterator'] = new NamedNodeMapGetIterator();
        $namedNodeMap->methodVisibility['getiterator'] = $pub;
        $namedNodeMap->methodNames['getiterator'] = 'getIterator';
        $ctx->classes[self::CLASS_NAMED_NODE_MAP] = $namedNodeMap;

        // Dom\TokenList only — php-src has no legacy DOMTokenList / DOMElement::$classList
        // (php_dom.stub.php; #28227, re-#16876 / #20512). Methods install in DomLivingBuiltinClasses.

        $xpath = new ClassEntry('DOMXPath');
        $xpath->isInternal = true;
        $xpathConstruct = new XPathConstruct();
        $xpath->constructor = $xpathConstruct;
        $xpath->methods['__construct'] = $xpathConstruct;
        $xpath->methodVisibility['__construct'] = $pub;
        $xpath->methods['query'] = new XPathQuery();
        $xpath->methodVisibility['query'] = $pub;
        $xpath->methods['evaluate'] = new XPathEvaluate();
        $xpath->methodVisibility['evaluate'] = $pub;
        $xpath->methods['registernamespace'] = new XPathRegisterNamespace();
        $xpath->methodVisibility['registernamespace'] = $pub;
        $xpath->methodNames['registernamespace'] = 'registerNamespace';
        $xpath->methods['registerphpfunctions'] = new XPathRegisterPhpFunctions();
        $xpath->methodVisibility['registerphpfunctions'] = $pub;
        $xpath->methodNames['registerphpfunctions'] = 'registerPhpFunctions';
        if (CompilerVersion::supportsDomXPathRegisterPhpFunctionNS()) {
            $xpath->methods['registerphpfunctionns'] = new XPathRegisterPhpFunctionNS();
            $xpath->methodVisibility['registerphpfunctionns'] = $pub;
            $xpath->methodNames['registerphpfunctionns'] = 'registerPhpFunctionNS';
        }
        if (CompilerVersion::supportsDomXPathQuote()) {
            $pubStatic = $pub | CfgFunc::FLAG_STATIC;
            $xpath->methods['quote'] = new XPathQuote();
            $xpath->methodVisibility['quote'] = $pubStatic;
        }
        // php-src php_dom.stub.php — DOMXPath::$document / $registerNodeNamespaces (#20842, #31753).
        $xpath->properties[] = self::stubDeclaredProperty(
            self::PROP_XPATH_DOCUMENT,
            'DOMDocument',
            self::CLASS_XPATH,
            $pub,
            true
        );
        $xpath->properties[] = self::stubDeclaredProperty(
            self::PROP_REGISTER_NODE_NAMESPACES,
            'bool',
            self::CLASS_XPATH,
            $pub,
            false
        );
        $ctx->classes[self::CLASS_XPATH] = $xpath;

        $impl = new ClassEntry('DOMImplementation');
        $impl->isInternal = true;
        $impl->methods['createdocument'] = new ImplementationCreateDocument();
        $impl->methodVisibility['createdocument'] = $pub;
        $impl->methods['createdocumenttype'] = new ImplementationCreateDocumentType();
        $impl->methodVisibility['createdocumenttype'] = $pub;
        $impl->methods['getfeature'] = new ImplementationGetFeature();
        $impl->methodVisibility['getfeature'] = $pub;
        $impl->methods['hasfeature'] = new ImplementationHasFeature();
        $impl->methodVisibility['hasfeature'] = $pub;
        self::$implementationClassEntry = $impl;
        $ctx->classes[self::CLASS_IMPLEMENTATION] = $impl;

        $doctype = new ClassEntry('DOMDocumentType');
        $doctype->isInternal = true;
        $doctype->parentLc = self::CLASS_NODE;
        $doctype->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_NAME, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_PUBLIC_ID, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_SYSTEM_ID, null, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_INTERNAL_SUBSET, $nullProto, $strProto);
        $doctype->properties[] = new ClassProperty(self::PROP_ENTITIES, $nullProto, $objProto);
        $doctype->properties[] = new ClassProperty(self::PROP_NOTATIONS, $nullProto, $objProto);
        $ctx->classes[self::CLASS_DOCUMENT_TYPE] = $doctype;

        $processingInstruction = new ClassEntry('DOMProcessingInstruction');
        $processingInstruction->isInternal = true;
        $processingInstruction->parentLc = self::CLASS_NODE;
        $processingInstruction->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $processingInstruction->properties[] = new ClassProperty(self::PROP_NODE_VALUE, $nullProto, $strProto);
        $processingInstruction->properties[] = new ClassProperty(self::PROP_TARGET, null, $strProto);
        $processingInstruction->properties[] = new ClassProperty(self::PROP_DATA, null, $strProto);
        // php-src ext/dom/processinginstruction.c — DOMProcessingInstruction::__construct (#24631).
        $piConstruct = new ProcessingInstructionConstruct();
        $processingInstruction->constructor = $piConstruct;
        $processingInstruction->methods['__construct'] = $piConstruct;
        $processingInstruction->methodVisibility['__construct'] = $pub;
        $ctx->classes[self::CLASS_PROCESSING_INSTRUCTION] = $processingInstruction;

        $document = new ClassEntry('DOMDocument');
        $document->isInternal = true;
        $document->parentLc = self::CLASS_NODE;
        // php-src php_dom.stub.php — DOMDocument implements DOMParentNode (#22389).
        $document->interfaces[] = 'domparentnode';
        $documentConstruct = new DocumentConstruct();
        $document->constructor = $documentConstruct;
        $document->methods['__construct'] = $documentConstruct;
        $document->methodVisibility['__construct'] = $pub;
        $document->properties[] = new ClassProperty(self::PROP_FORMAT_OUTPUT, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_VALIDATE_ON_PARSE, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_RESOLVE_EXTERNALS, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_SUBSTITUTE_ENTITIES, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_PRESERVE_WHITE_SPACE, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_RECOVER, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_STRICT_ERROR_CHECKING, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_ENCODING, $nullProto, $strProto);
        $document->properties[] = new ClassProperty(self::PROP_XML_ENCODING, $nullProto, $strProto);
        // Legacy Level-3 aliases (php-src php_dom.stub.php / document.c; #28587).
        $document->properties[] = new ClassProperty(self::PROP_ACTUAL_ENCODING, $nullProto, $strProto);
        $document->properties[] = new ClassProperty(self::PROP_XML_VERSION, null, $strProto);
        $document->properties[] = new ClassProperty(self::PROP_VERSION, null, $strProto);
        $document->properties[] = new ClassProperty(self::PROP_XML_STANDALONE, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_STANDALONE, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_CONFIG, $nullProto, $nullProto);
        $document->properties[] = new ClassProperty(self::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        // Element-id map + registry id — phpInvisible engine slots (#31439, peer WeakMap #22513).
        $elementIdMapProp = new ClassProperty(self::PROP_ELEMENT_ID_MAP, $nullProto, $arrayProto);
        $elementIdMapProp->phpInvisible = true;
        $document->properties[] = $elementIdMapProp;
        $documentRegistryIdProp = new ClassProperty(self::PROP_REGISTRY_ID, null, $intProto);
        $documentRegistryIdProp->phpInvisible = true;
        $document->properties[] = $documentRegistryIdProp;
        // ParentNode on Document (php-src ext/dom/parentnode.c; #19431).
        $document->properties[] = new ClassProperty(self::PROP_FIRST_ELEMENT_CHILD, $nullProto, $objProto);
        $document->properties[] = new ClassProperty(self::PROP_LAST_ELEMENT_CHILD, $nullProto, $objProto);
        $document->properties[] = new ClassProperty(self::PROP_CHILD_ELEMENT_COUNT, null, $intProto);
        // php-src php_dom.stub.php — document-only declared props (#31753).
        $document->properties[] = self::stubDeclaredProperty(
            self::PROP_DOCTYPE,
            '?DOMDocumentType',
            self::CLASS_DOCUMENT,
            $pub,
            true,
            $nullProto
        );
        $document->properties[] = self::stubDeclaredProperty(
            self::PROP_IMPLEMENTATION,
            'DOMImplementation',
            self::CLASS_DOCUMENT,
            $pub,
            true
        );
        $document->properties[] = self::stubDeclaredProperty(
            self::PROP_DOCUMENT_URI,
            '?string',
            self::CLASS_DOCUMENT,
            $pub,
            false,
            $nullProto
        );
        $document->methods['loadxml'] = new DocumentLoadXML();
        $document->methodVisibility['loadxml'] = $pub;
        $document->methods['load'] = new DocumentLoad();
        $document->methodVisibility['load'] = $pub;
        $document->methods['loadhtml'] = new DocumentLoadHTML();
        $document->methodVisibility['loadhtml'] = $pub;
        $document->methodNames['loadhtml'] = 'loadHTML';
        $document->methods['loadhtmlfile'] = new DocumentLoadHTMLFile();
        $document->methodVisibility['loadhtmlfile'] = $pub;
        $document->methodNames['loadhtmlfile'] = 'loadHTMLFile';
        // php-src ext/dom/php_dom.stub.php — load*/loadHTML*: bool (#28713).
        // InternalArgInfo omits return on load/loadXML and omits $options on loadHTML*.
        $loadBoolRet = ReflectionTypeSupport::cfgTypeFromLabel('bool');
        if (null !== $loadBoolRet) {
            $document->methodReturnDeclaredTypes['load'] = $loadBoolRet;
            $document->methodReturnDeclaredTypes['loadxml'] = $loadBoolRet;
            $document->methodReturnDeclaredTypes['loadhtml'] = $loadBoolRet;
            $document->methodReturnDeclaredTypes['loadhtmlfile'] = $loadBoolRet;
        }
        $document->methods['createelement'] = new DocumentCreateElement();
        $document->methodVisibility['createelement'] = $pub;
        $document->methods['createelementns'] = new DocumentCreateElementNS();
        $document->methodVisibility['createelementns'] = $pub;
        $document->methods['createattributens'] = new DocumentCreateAttributeNS();
        $document->methodVisibility['createattributens'] = $pub;
        $document->methods['createattribute'] = new DocumentCreateAttribute();
        $document->methodVisibility['createattribute'] = $pub;
        $document->methods['createdocumentfragment'] = new DocumentCreateDocumentFragment();
        $document->methodVisibility['createdocumentfragment'] = $pub;
        $document->methods['createentityreference'] = new DocumentCreateEntityReference();
        $document->methodVisibility['createentityreference'] = $pub;
        $document->methodNames['createentityreference'] = 'createEntityReference';
        $document->methods['createtextnode'] = new DocumentCreateTextNode();
        $document->methodVisibility['createtextnode'] = $pub;
        $document->methodNames['createtextnode'] = 'createTextNode';
        $document->methods['createcomment'] = new DocumentCreateComment();
        $document->methodVisibility['createcomment'] = $pub;
        $document->methodNames['createcomment'] = 'createComment';
        $document->methods['createcdatasection'] = new DocumentCreateCDATASection();
        $document->methodVisibility['createcdatasection'] = $pub;
        $document->methodNames['createcdatasection'] = 'createCDATASection';
        $document->methods['createprocessinginstruction'] = new DocumentCreateProcessingInstruction();
        $document->methodVisibility['createprocessinginstruction'] = $pub;
        $document->methodNames['createprocessinginstruction'] = 'createProcessingInstruction';
        $document->methods['appendchild'] = new DocumentAppendChild();
        $document->methodVisibility['appendchild'] = $pub;
        $document->methods['savexml'] = new DocumentSaveXML();
        $document->methodVisibility['savexml'] = $pub;
        $document->methods['save'] = new DocumentSave();
        $document->methodVisibility['save'] = $pub;
        $document->methods['savehtml'] = new DocumentSaveHTML();
        $document->methodVisibility['savehtml'] = $pub;
        $document->methodNames['savehtml'] = 'saveHTML';
        $document->methods['savehtmlfile'] = new DocumentSaveHTMLFile();
        $document->methodVisibility['savehtmlfile'] = $pub;
        $document->methodNames['savehtmlfile'] = 'saveHTMLFile';
        $document->methods['getelementsbytagname'] = new DocumentGetElementsByTagName();
        $document->methodVisibility['getelementsbytagname'] = $pub;
        $document->methods['getelementsbytagnamens'] = new DocumentGetElementsByTagNameNS();
        $document->methodVisibility['getelementsbytagnamens'] = $pub;
        $document->methodNames['getelementsbytagnamens'] = 'getElementsByTagNameNS';
        $document->methods['getelementbyid'] = new DocumentGetElementById();
        $document->methodVisibility['getelementbyid'] = $pub;
        $document->methods['importnode'] = new DocumentImportNode();
        $document->methodVisibility['importnode'] = $pub;
        $document->methods['adoptnode'] = new DocumentAdoptNode();
        $document->methodVisibility['adoptnode'] = $pub;
        $document->methodNames['adoptnode'] = 'adoptNode';
        $document->methods['registernodeclass'] = new DocumentRegisterNodeClass();
        $document->methodVisibility['registernodeclass'] = $pub;
        $document->methodNames['registernodeclass'] = 'registerNodeClass';
        $document->methods['normalizedocument'] = new DocumentNormalizeDocument();
        $document->methodVisibility['normalizedocument'] = $pub;
        $document->methodNames['normalizedocument'] = 'normalizeDocument';
        $document->methods['xinclude'] = new DocumentXInclude();
        $document->methodVisibility['xinclude'] = $pub;
        $document->methodNames['xinclude'] = 'xinclude';
        $document->methods['schemavalidate'] = new DocumentSchemaValidate();
        $document->methodVisibility['schemavalidate'] = $pub;
        $document->methodNames['schemavalidate'] = 'schemaValidate';
        $document->methods['relaxngvalidate'] = new DocumentRelaxNGValidate();
        $document->methodVisibility['relaxngvalidate'] = $pub;
        $document->methodNames['relaxngvalidate'] = 'relaxNGValidate';
        $document->methods['schemavalidatesource'] = new DocumentSchemaValidateSource();
        $document->methodVisibility['schemavalidatesource'] = $pub;
        $document->methodNames['schemavalidatesource'] = 'schemaValidateSource';
        $document->methods['relaxngvalidatesource'] = new DocumentRelaxNGValidateSource();
        $document->methodVisibility['relaxngvalidatesource'] = $pub;
        $document->methodNames['relaxngvalidatesource'] = 'relaxNGValidateSource';
        $document->methods['validate'] = new DocumentValidate();
        $document->methodVisibility['validate'] = $pub;
        // DOMParentNode — Document / Element / DocumentFragment only (php_dom.stub.php; #23155).
        self::registerParentNodeMutationMethods($document, $pub);
        $ctx->classes[self::CLASS_DOCUMENT] = $document;

        $element = new ClassEntry('DOMElement');
        $element->isInternal = true;
        $element->parentLc = self::CLASS_NODE;
        // php-src php_dom.stub.php — DOMElement implements DOMParentNode, DOMChildNode (#22389).
        $element->interfaces[] = 'domparentnode';
        $element->interfaces[] = 'domchildnode';
        $element->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $element->properties[] = new ClassProperty(self::PROP_TAG_NAME, null, $strProto);
        // PHP 8.3+ virtual HTML id/className (php_dom.stub.php; #22457).
        if (CompilerVersion::supportsDomElementIdClassNameProperties()) {
            $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_ID, $nullProto, $strProto);
            $element->properties[] = new ClassProperty(DomHtmlElementPropertySupport::PROP_CLASS_NAME, $nullProto, $strProto);
        }
        $element->properties[] = new ClassProperty(self::PROP_ATTRIBUTES, $nullProto, $objProto);
        // php-src php_dom.stub.php — mixed schemaTypeInfo always null (#31753).
        $element->properties[] = self::stubDeclaredProperty(
            self::PROP_SCHEMA_TYPE_INFO,
            'mixed',
            self::CLASS_ELEMENT,
            $pub,
            true,
            $nullProto
        );
        // ParentNode + NonDocumentTypeChildNode on Element (#19431).
        $element->properties[] = new ClassProperty(self::PROP_FIRST_ELEMENT_CHILD, $nullProto, $objProto);
        $element->properties[] = new ClassProperty(self::PROP_LAST_ELEMENT_CHILD, $nullProto, $objProto);
        $element->properties[] = new ClassProperty(self::PROP_CHILD_ELEMENT_COUNT, null, $intProto);
        $element->properties[] = new ClassProperty(self::PROP_NEXT_ELEMENT_SIBLING, $nullProto, $objProto);
        $element->properties[] = new ClassProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING, $nullProto, $objProto);
        // php-src ext/dom/element.c — DOMElement::__construct orphaned element (#22598).
        $elementConstruct = new ElementConstruct();
        $element->constructor = $elementConstruct;
        $element->methods['__construct'] = $elementConstruct;
        $element->methodVisibility['__construct'] = $pub;
        $element->methods['appendchild'] = new ElementAppendChild();
        $element->methodVisibility['appendchild'] = $pub;
        $element->methods['getattribute'] = new ElementGetAttribute();
        $element->methodVisibility['getattribute'] = $pub;
        $element->methods['getattributenode'] = new ElementGetAttributeNode();
        $element->methodVisibility['getattributenode'] = $pub;
        $element->methods['getattributenodens'] = new ElementGetAttributeNodeNS();
        $element->methodVisibility['getattributenodens'] = $pub;
        $element->methodNames['getattributenodens'] = 'getAttributeNodeNS';
        $element->methods['getattributens'] = new ElementGetAttributeNS();
        $element->methodVisibility['getattributens'] = $pub;
        $element->methods['hasattribute'] = new ElementHasAttribute();
        $element->methodVisibility['hasattribute'] = $pub;
        $element->methods['hasattributens'] = new ElementHasAttributeNS();
        $element->methodVisibility['hasattributens'] = $pub;
        $element->methods['removeattribute'] = new ElementRemoveAttribute();
        $element->methodVisibility['removeattribute'] = $pub;
        $element->methods['removeattributenode'] = new ElementRemoveAttributeNode();
        $element->methodVisibility['removeattributenode'] = $pub;
        $element->methods['setattribute'] = new ElementSetAttribute();
        $element->methodVisibility['setattribute'] = $pub;
        $element->methods['setattributenode'] = new ElementSetAttributeNode();
        $element->methodVisibility['setattributenode'] = $pub;
        $element->methods['setattributenodens'] = new ElementSetAttributeNodeNS();
        $element->methodVisibility['setattributenodens'] = $pub;
        $element->methodNames['setattributenodens'] = 'setAttributeNodeNS';
        $element->methods['setattributens'] = new ElementSetAttributeNS();
        $element->methodVisibility['setattributens'] = $pub;
        $element->methods['removeattributens'] = new ElementRemoveAttributeNS();
        $element->methodVisibility['removeattributens'] = $pub;
        $element->methods['setidattribute'] = new ElementSetIdAttribute();
        $element->methodVisibility['setidattribute'] = $pub;
        $element->methodNames['setidattribute'] = 'setIdAttribute';
        $element->methods['setidattributens'] = new ElementSetIdAttributeNS();
        $element->methodVisibility['setidattributens'] = $pub;
        $element->methodNames['setidattributens'] = 'setIdAttributeNS';
        $element->methods['setidattributenode'] = new ElementSetIdAttributeNode();
        $element->methodVisibility['setidattributenode'] = $pub;
        $element->methodNames['setidattributenode'] = 'setIdAttributeNode';
        $element->methods['getelementsbytagname'] = new ElementGetElementsByTagName();
        $element->methodVisibility['getelementsbytagname'] = $pub;
        $element->methods['getelementsbytagnamens'] = new ElementGetElementsByTagNameNS();
        $element->methodVisibility['getelementsbytagnamens'] = $pub;
        $element->methodNames['getelementsbytagnamens'] = 'getElementsByTagNameNS';
        if (CompilerVersion::supportsDomElementGetAttributeNames()) {
            $element->methods['getattributenames'] = new ElementGetAttributeNames();
            $element->methodVisibility['getattributenames'] = $pub;
            $element->methodNames['getattributenames'] = 'getAttributeNames';
        }
        if (CompilerVersion::supportsDomElementInsertAdjacentHtml()) {
            $element->methods['insertadjacenthtml'] = new ElementInsertAdjacentHTML();
            $element->methodVisibility['insertadjacenthtml'] = $pub;
            $element->methodNames['insertadjacenthtml'] = 'insertAdjacentHTML';
        }
        if (CompilerVersion::supportsDomElementInsertAdjacentElement()) {
            $element->methods['insertadjacentelement'] = new ElementInsertAdjacentElement();
            $element->methodVisibility['insertadjacentelement'] = $pub;
            $element->methodNames['insertadjacentelement'] = 'insertAdjacentElement';
        }
        if (CompilerVersion::supportsDomElementInsertAdjacentText()) {
            $element->methods['insertadjacenttext'] = new ElementInsertAdjacentText();
            $element->methodVisibility['insertadjacenttext'] = $pub;
            $element->methodNames['insertadjacenttext'] = 'insertAdjacentText';
        }
        if (CompilerVersion::supportsDomElementToggleAttribute()) {
            $element->methods['toggleattribute'] = new ElementToggleAttribute();
            $element->methodVisibility['toggleattribute'] = $pub;
            $element->methodNames['toggleattribute'] = 'toggleAttribute';
        }
        if (CompilerVersion::supportsDomElementInnerOuterHtml()) {
            $element->methods['getinnerhtml'] = new ElementGetInnerHTML();
            $element->methodVisibility['getinnerhtml'] = $pub;
            $element->methodNames['getinnerhtml'] = 'getInnerHTML';
            $element->methods['getouterhtml'] = new ElementGetOuterHTML();
            $element->methodVisibility['getouterhtml'] = $pub;
            $element->methodNames['getouterhtml'] = 'getOuterHTML';
        }
        // DOMElement has no $classList — living Dom\Element only (php_dom.stub.php; #28227).
        // DOMParentNode + DOMChildNode (php_dom.stub.php; #23155).
        self::registerParentNodeMutationMethods($element, $pub);
        self::registerChildNodeMutationMethods($element, $pub);
        $ctx->classes[self::CLASS_ELEMENT] = $element;

        $fragment = new ClassEntry('DOMDocumentFragment');
        $fragment->isInternal = true;
        $fragment->parentLc = self::CLASS_NODE;
        // php-src php_dom.stub.php — DOMDocumentFragment implements DOMParentNode (#22389).
        $fragment->interfaces[] = 'domparentnode';
        $fragmentConstruct = new FragmentConstruct();
        $fragment->constructor = $fragmentConstruct;
        $fragment->methods['__construct'] = $fragmentConstruct;
        $fragment->methodVisibility['__construct'] = $pub;
        $fragment->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        // ParentNode on DocumentFragment (php-src ext/dom/parentnode.c; #19431).
        $fragment->properties[] = new ClassProperty(self::PROP_FIRST_ELEMENT_CHILD, $nullProto, $objProto);
        $fragment->properties[] = new ClassProperty(self::PROP_LAST_ELEMENT_CHILD, $nullProto, $objProto);
        $fragment->properties[] = new ClassProperty(self::PROP_CHILD_ELEMENT_COUNT, null, $intProto);
        $fragment->methods['appendchild'] = new FragmentAppendChild();
        $fragment->methodVisibility['appendchild'] = $pub;
        $fragment->methods['appendxml'] = new FragmentAppendXML();
        $fragment->methodVisibility['appendxml'] = $pub;
        $fragment->methodNames['appendxml'] = 'appendXML';
        // DOMParentNode only — not ChildNode (php_dom.stub.php; #23155).
        self::registerParentNodeMutationMethods($fragment, $pub);
        $ctx->classes[self::CLASS_DOCUMENT_FRAGMENT] = $fragment;
    }

    /**
     * DOMParentNode mutators — Document / Element / DocumentFragment (php_dom.stub.php; #23155).
     */
    private static function registerParentNodeMutationMethods(ClassEntry $entry, int $pub): void
    {
        $entry->methods['append'] = new NodeAppend();
        $entry->methodVisibility['append'] = $pub;
        $entry->methods['prepend'] = new NodePrepend();
        $entry->methodVisibility['prepend'] = $pub;
        if (CompilerVersion::supportsDomNodeReplaceChildren()) {
            $entry->methods['replacechildren'] = new NodeReplaceChildren();
            $entry->methodVisibility['replacechildren'] = $pub;
            $entry->methodNames['replacechildren'] = 'replaceChildren';
        }
    }

    /**
     * DOMChildNode mutators — Element + CharacterData only (php_dom.stub.php; #23155).
     */
    private static function registerChildNodeMutationMethods(ClassEntry $entry, int $pub): void
    {
        $entry->methods['before'] = new NodeBefore();
        $entry->methodVisibility['before'] = $pub;
        $entry->methods['after'] = new NodeAfter();
        $entry->methodVisibility['after'] = $pub;
        $entry->methods['replacewith'] = new NodeReplaceWith();
        $entry->methodVisibility['replacewith'] = $pub;
        $entry->methodNames['replacewith'] = 'replaceWith';
        $entry->methods['remove'] = new NodeRemove();
        $entry->methodVisibility['remove'] = $pub;
    }

    public static function createDocumentType(
        Context $ctx,
        string $qualifiedName,
        string $publicId,
        string $systemId,
        ?ObjectEntry $forDocument = null,
        bool $living = false
    ): Variable {
        if ($living || (null !== $forDocument && VmDomLiving::isLivingDocument($forDocument))) {
            $class = $ctx->classes[VmDomLiving::CLASS_DOCUMENT_TYPE] ?? null;
            if (null === $class) {
                throw new \LogicException('Dom\\DocumentType is not registered in this compiler build');
            }
        } else {
            $class = self::resolveNodeClass($ctx, $forDocument, self::CLASS_DOCUMENT_TYPE);
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_TYPE_NODE;
        $state->nodeName = $qualifiedName;
        $state->publicId = $publicId;
        $state->systemId = $systemId;
        $state->internalSubset = null;
        DomRegistry::attach($entry, $state);
        self::initDocumentTypePropertySlots($entry, $qualifiedName, $publicId, $systemId);
        $entitiesMap = self::createNamedNodeMap($ctx, [], $entry, true);
        $notationsMap = self::createNamedNodeMap($ctx, [], $entry, true);
        $state->entitiesMapId = $entitiesMap->toObject()->id;
        $state->notationsMapId = $notationsMap->toObject()->id;
        $entry->getProperty(self::PROP_ENTITIES)->copyFrom($entitiesMap);
        $entry->getProperty(self::PROP_NOTATIONS)->copyFrom($notationsMap);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createDocument(
        Context $ctx,
        ?string $namespaceUri,
        string $qualifiedName,
        ?ObjectEntry $doctype,
        bool $living = false
    ): Variable {
        $typeLabel = $living ? 'Dom\\DocumentType' : 'DOMDocumentType';
        if (null !== $doctype && !self::isDocumentType($doctype)) {
            throw new \TypeError(sprintf(
                '%s::createDocument(): Argument #3 ($doctype) must be of type %s or null',
                $living ? 'Dom\\Implementation' : 'DOMImplementation',
                $typeLabel
            ));
        }

        if ($living) {
            $docVar = VmDomLiving::createXmlEmpty($ctx, '1.0', 'UTF-8');
            $entry = $docVar->toObject();
            $state = DomRegistry::state($entry);
        } else {
            $class = $ctx->classes[self::CLASS_DOCUMENT] ?? null;
            if (null === $class) {
                throw new \LogicException('DOMDocument is not registered in this compiler build');
            }

            $entry = new ObjectEntry($class);
            $entry->constructed = true;
            $entry->getProperty(self::PROP_FORMAT_OUTPUT)->bool(false);
            self::initNodePropertySlots($entry);

            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
            $state->nodeName = '#document';
            DomRegistry::attach($entry, $state);
            self::ensureChildNodesList($ctx, $entry);
        }

        $state->namespaceUri = $namespaceUri;
        $state->documentElementName = $qualifiedName;

        $childIds = [];
        if (null !== $doctype) {
            self::adoptDocumentTypeIntoDocument($doctype, $entry);
            $childIds[] = $doctype->id;
        }

        if ('' !== $qualifiedName) {
            $rootVar = null !== $namespaceUri && '' !== $namespaceUri
                ? self::createElementNS($ctx, $namespaceUri, $qualifiedName, $entry)
                : self::createElement($ctx, $qualifiedName, $entry);
            $root = $rootVar->toObject();
            $childIds[] = $root->id;
            $entry->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($root);
            self::linkChildToParent($root, $entry);
            self::propagateDocumentId($root, $entry->id);
        }

        $state->childIds = $childIds;
        self::syncSubtree($ctx, $entry);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function hasFeature(string $feature, string $version): bool
    {
        $feature = strtoupper($feature);
        $version = trim($version);

        if ('CORE' === $feature) {
            return '1.0' === $version;
        }
        if ('XML' === $feature) {
            return '1.0' === $version || '2.0' === $version;
        }

        return false;
    }

    public static function implementationSingleton(): ObjectEntry
    {
        if (null === self::$implementationClassEntry) {
            throw new \LogicException('DOMImplementation is not registered in this compiler build');
        }
        $key = spl_object_id(self::$implementationClassEntry);
        if (!isset(self::$implementationSingletons[$key])) {
            $entry = new ObjectEntry(self::$implementationClassEntry);
            $entry->constructed = true;
            self::$implementationSingletons[$key] = $entry;
        }

        return self::$implementationSingletons[$key];
    }

    public static function isDefaultNamespace(ObjectEntry $node, string $namespaceUri): bool
    {
        $defaultNs = self::lookupNamespaceURI($node, null);
        if (null === $defaultNs) {
            return false;
        }

        return $defaultNs === $namespaceUri;
    }

    public static function ensureDocument(ObjectEntry $document, bool $deferPropertyInit = false): DomNodeState
    {
        if (!DomRegistry::has($document)) {
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
            $state->nodeName = '#document';
            DomRegistry::attach($document, $state);
            if (!$deferPropertyInit) {
                self::initDocumentLibxmlDefaults($document);
                self::initNodePropertySlots($document);
            }
        }

        return DomRegistry::state($document);
    }

    /** Zend default libxml parser/writer options on fresh DOMDocument (php-src ext/dom/document.c; #14368). */
    public static function initDocumentLibxmlDefaults(ObjectEntry $document): void
    {
        self::setDocumentBoolSlot($document, self::PROP_FORMAT_OUTPUT, false);
        self::setDocumentBoolSlot($document, self::PROP_VALIDATE_ON_PARSE, false);
        self::setDocumentBoolSlot($document, self::PROP_RESOLVE_EXTERNALS, false);
        self::setDocumentBoolSlot($document, self::PROP_SUBSTITUTE_ENTITIES, false);
        self::setDocumentBoolSlot($document, self::PROP_PRESERVE_WHITE_SPACE, true);
        self::setDocumentBoolSlot($document, self::PROP_RECOVER, false);
        self::setDocumentBoolSlot($document, self::PROP_STRICT_ERROR_CHECKING, true);
        if ($document->hasProperty(self::PROP_XML_VERSION)) {
            $document->getProperty(self::PROP_XML_VERSION)->string('1.0');
        }
        if ($document->hasProperty(self::PROP_ENCODING)) {
            $document->getProperty(self::PROP_ENCODING)->null();
        }
        if ($document->hasProperty(self::PROP_XML_STANDALONE)) {
            self::setDocumentBoolSlot($document, self::PROP_XML_STANDALONE, false);
        }
    }

    /** Mirror DomRegistry object id onto the document for LLVM helper bridges (#17954). */
    public static function initRegistryIdProperty(ObjectEntry $document): void
    {
        if (!$document->hasProperty(self::PROP_REGISTRY_ID)) {
            return;
        }
        $idVar = new Variable();
        $idVar->int($document->id);
        $document->getProperty(self::PROP_REGISTRY_ID)->copyFrom($idVar);
    }

    /** Empty id map for fresh documents; loadHTML replaces via {@see syncElementIdMapProperty()}. */
    public static function initElementIdMapProperty(ObjectEntry $document): void
    {
        if (!$document->hasProperty(self::PROP_ELEMENT_ID_MAP)) {
            return;
        }
        $ht = new HashTable();
        $var = new Variable();
        $var->array($ht);
        $document->getProperty(self::PROP_ELEMENT_ID_MAP)->copyFrom($var);
    }

    /** Mirror DomRegistry elementIds onto the document for LLVM getElementById() (#17954). */
    public static function syncElementIdMapProperty(ObjectEntry $document): void
    {
        if (!$document->hasProperty(self::PROP_ELEMENT_ID_MAP)) {
            return;
        }
        $state = DomRegistry::state($document);
        $ht = new HashTable();
        foreach ($state->elementIds as $id => $objectId) {
            $entry = DomRegistry::entry($objectId);
            if (null !== $entry) {
                // PHP coerces numeric-string array keys to int ("1" → 1); HashTable::add needs string (#21644).
                $ht->add((string) $id, self::elementVariable($entry));
            }
        }
        $var = new Variable();
        $var->array($ht);
        $document->getProperty(self::PROP_ELEMENT_ID_MAP)->copyFrom($var);
    }

    private static function setDocumentBoolSlot(ObjectEntry $document, string $propName, bool $value): void
    {
        if (!$document->hasProperty($propName)) {
            return;
        }
        $document->getProperty($propName)->bool($value);
    }

    public static function ensureDocumentFragment(ObjectEntry $fragment): DomNodeState
    {
        if (!DomRegistry::has($fragment)) {
            $fragLc = strtolower($fragment->class->name);
            if (self::CLASS_DOCUMENT_FRAGMENT !== $fragLc
                && VmDomLiving::CLASS_DOCUMENT_FRAGMENT !== $fragLc
            ) {
                throw new \LogicException('ensureDocumentFragment() expects a DOMDocumentFragment in this compiler build');
            }
            if ($fragment->hasProperty(self::PROP_NODE_NAME)) {
                $fragment->getProperty(self::PROP_NODE_NAME)->string('#document-fragment');
            }
            self::initNodePropertySlots($fragment);
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_FRAG_NODE;
            $state->nodeName = '#document-fragment';
            DomRegistry::attach($fragment, $state);
        }

        return DomRegistry::state($fragment);
    }

    /**
     * User-script AOT: LLVM-materialized DOM objects may lack DomRegistry state (#18927).
     */
    public static function ensureDomTreeNodeRegistered(
        Context $ctx,
        ObjectEntry $entry,
        ?ObjectEntry $ownerDocument = null
    ): void {
        if (DomRegistry::has($entry)) {
            return;
        }

        $classLc = strtolower($entry->class->name);
        if (self::CLASS_DOCUMENT === $classLc
            || VmDomLiving::CLASS_HTML_DOCUMENT === $classLc
            || VmDomLiving::CLASS_XML_DOCUMENT === $classLc
            || VmDomLiving::CLASS_DOCUMENT === $classLc
        ) {
            self::ensureDocument($entry);

            return;
        }
        if (self::CLASS_DOCUMENT_FRAGMENT === $classLc
            || VmDomLiving::CLASS_DOCUMENT_FRAGMENT === $classLc
        ) {
            self::ensureDocumentFragment($entry);

            return;
        }
        if (self::CLASS_ELEMENT !== $classLc
            && VmDomLiving::CLASS_ELEMENT !== $classLc
            && VmDomLiving::CLASS_HTML_ELEMENT !== $classLc
        ) {
            throw new \LogicException('DOM object has no registered node state in this compiler build');
        }

        $name = self::PROP_NODE_NAME;
        $nodeName = 'unknown';
        if ($entry->hasProperty($name)) {
            $nameVar = $entry->getProperty($name)->resolveIndirect();
            if (Variable::TYPE_STRING === $nameVar->type) {
                $nodeName = $nameVar->toString();
            }
        }
        if ($entry->hasProperty(self::PROP_TAG_NAME)) {
            $entry->getProperty(self::PROP_TAG_NAME)->string($nodeName);
        }
        self::initElementPropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $nodeName;
        $state->localName = $nodeName;
        if (null !== $ownerDocument) {
            self::ensureDomTreeNodeRegistered($ctx, $ownerDocument);
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);
        if (CompilerVersion::supportsDomTokenList()) {
            self::syncElementClassList($ctx, $entry);
        }
        self::ensureChildNodesList($ctx, $entry);
        self::ensureElementAttributesMap($ctx, $entry);
    }

    /**
     * DOMElement::__construct — initialize an already-allocated orphaned element
     * (php-src ext/dom/element.c php_dom_create_element; #22598).
     *
     * Empty $namespaceURI matches the stub default and means no namespace (unlike createElementNS("")).
     */
    public static function constructElement(
        Context $ctx,
        ObjectEntry $entry,
        string $qualifiedName,
        ?string $value = null,
        string $namespaceURI = ''
    ): void {
        if (self::CLASS_ELEMENT !== strtolower($entry->class->name)
            && !VmDomLiving::isLivingElement($entry)
        ) {
            throw new \LogicException('constructElement() expects a DOMElement in this compiler build');
        }
        $text = null !== $value ? $value : '';
        if ('' === $namespaceURI) {
            // Prefixed QName without URI → Namespace Error; bare / leading-colon names use xmlValidateName
            // (php-src dom_element_document_fragment_or_element_new; #22598).
            $colon = strpos($qualifiedName, ':');
            if (false !== $colon && $colon > 0) {
                self::assertValidElementNSName('', $qualifiedName);
            }
            self::assertValidXmlName($qualifiedName);
            self::writeElementNameSlots($entry, $qualifiedName);
            self::initElementPropertySlots($entry);
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_ELEMENT_NODE;
            $state->nodeName = $qualifiedName;
            $state->localName = $qualifiedName;
            $state->prefix = null;
            $state->namespaceUri = null;
            DomRegistry::attach($entry, $state);
        } else {
            self::assertValidElementNSName($namespaceURI, $qualifiedName);
            [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
            self::writeElementNameSlots($entry, $qualifiedName);
            self::initElementPropertySlots($entry);
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_ELEMENT_NODE;
            $state->nodeName = $qualifiedName;
            $state->localName = $localName;
            $state->prefix = '' !== $prefix ? $prefix : null;
            $state->namespaceUri = $namespaceURI;
            $state->namespaceDeclarations['' !== $prefix ? $prefix : ''] = $namespaceURI;
            DomRegistry::attach($entry, $state);
        }
        if ('' !== $text) {
            self::writeTextContent($ctx, $entry, $text);
        }
        if (CompilerVersion::supportsDomTokenList()) {
            self::syncElementClassList($ctx, $entry);
        }
        self::ensureChildNodesList($ctx, $entry);
        self::ensureElementAttributesMap($ctx, $entry);
    }

    /**
     * DOMText::__construct — initialize an already-allocated orphaned text node
     * (php-src ext/dom/text.c; #24631).
     */
    public static function constructText(Context $ctx, ObjectEntry $entry, string $data): void
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_TEXT !== $lc && VmDomLiving::CLASS_TEXT !== $lc) {
            throw new \LogicException('constructText() expects a DOMText in this compiler build');
        }
        $entry->getProperty(self::PROP_NODE_NAME)->string('#text');
        self::initNodePropertySlots($entry);
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_TEXT_NODE;
        $state->nodeName = '#text';
        $state->textContent = $data;
        DomRegistry::attach($entry, $state);
    }

    /**
     * DOMComment::__construct — orphaned comment (php-src ext/dom/comment.c; #24631).
     */
    public static function constructComment(Context $ctx, ObjectEntry $entry, string $data): void
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_COMMENT !== $lc && VmDomLiving::CLASS_COMMENT !== $lc) {
            throw new \LogicException('constructComment() expects a DOMComment in this compiler build');
        }
        $entry->getProperty(self::PROP_NODE_NAME)->string('#comment');
        self::initNodePropertySlots($entry);
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_COMMENT_NODE;
        $state->nodeName = '#comment';
        $state->textContent = $data;
        DomRegistry::attach($entry, $state);
    }

    /**
     * DOMCdataSection::__construct — orphaned CDATA (php-src ext/dom/cdatasection.c; #24631).
     */
    public static function constructCdataSection(Context $ctx, ObjectEntry $entry, string $data): void
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_CDATA !== $lc && VmDomLiving::CLASS_CDATA !== $lc) {
            throw new \LogicException('constructCdataSection() expects a DOMCdataSection in this compiler build');
        }
        $entry->getProperty(self::PROP_NODE_NAME)->string('#cdata-section');
        self::initNodePropertySlots($entry);
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_CDATA_SECTION_NODE;
        $state->nodeName = '#cdata-section';
        $state->textContent = $data;
        DomRegistry::attach($entry, $state);
    }

    /**
     * DOMProcessingInstruction::__construct — orphaned PI (php-src ext/dom/processinginstruction.c; #24631).
     */
    public static function constructProcessingInstruction(
        Context $ctx,
        ObjectEntry $entry,
        string $target,
        string $data
    ): void {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_PROCESSING_INSTRUCTION !== $lc
            && VmDomLiving::CLASS_PROCESSING_INSTRUCTION !== $lc
        ) {
            throw new \LogicException(
                'constructProcessingInstruction() expects a DOMProcessingInstruction in this compiler build'
            );
        }
        self::assertValidProcessingInstructionTarget($target);
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_PROCESSING_INSTRUCTION_NODE;
        $state->nodeName = $target;
        $state->textContent = $data;
        DomRegistry::attach($entry, $state);
        $entry->getProperty(self::PROP_NODE_NAME)->string($target);
        $entry->getProperty(self::PROP_NODE_VALUE)->string($data);
        $entry->getProperty(self::PROP_TARGET)->string($target);
        $entry->getProperty(self::PROP_DATA)->string($data);
        self::initNodePropertySlots($entry);
    }

    /**
     * DOMEntityReference::__construct — orphaned entity ref (php-src ext/dom/entityreference.c; #24631).
     */
    public static function constructEntityReference(Context $ctx, ObjectEntry $entry, string $name): void
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_ENTITY_REFERENCE !== $lc && VmDomLiving::CLASS_ENTITY_REFERENCE !== $lc) {
            throw new \LogicException(
                'constructEntityReference() expects a DOMEntityReference in this compiler build'
            );
        }
        self::assertValidEntityReferenceName($name);
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        self::initNodePropertySlots($entry);
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ENTITY_REF_NODE;
        $state->nodeName = $name;
        DomRegistry::attach($entry, $state);
    }

    /**
     * DOMAttr::__construct — orphaned attribute (php-src ext/dom/attr.c; #24631).
     */
    public static function constructAttr(Context $ctx, ObjectEntry $entry, string $name, string $value): void
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_ATTR !== $lc && VmDomLiving::CLASS_ATTR !== $lc) {
            throw new \LogicException('constructAttr() expects a DOMAttr in this compiler build');
        }
        self::assertValidXmlName($name);
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        $entry->getProperty(self::PROP_NAME)->string($name);
        $entry->getProperty(self::PROP_VALUE)->string($value);
        $entry->getProperty(self::PROP_OWNER_ELEMENT)->null();
        self::initNodePropertySlots($entry);
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ATTRIBUTE_NODE;
        $state->nodeName = $name;
        $state->localName = $name;
        $state->prefix = null;
        $state->namespaceUri = null;
        $state->textContent = $value;
        DomRegistry::attach($entry, $state);
    }

    public static function createElement(
        Context $ctx,
        string $name,
        ?ObjectEntry $ownerDocument = null,
        string $value = ''
    ): Variable {
        // php-src document.c / xmlValidateName — Invalid Character Error (#20594).
        // Non-strict: php_dom_throw_error → warning + RETURN_FALSE (#24804).
        if (!self::isValidXmlName($name)) {
            self::phpDomThrowError(
                $ctx,
                $ownerDocument,
                DomExceptionConstants::INVALID_CHARACTER_ERR,
                'Invalid Character Error',
                'DOMDocument::createElement()'
            );

            return self::falseBoolVariable();
        }
        // Dom\HTMLDocument::createElement — lowercase + HTML namespace (php-src document.c; #21030).
        // Dom\HTML_NO_DEFAULT_NS during loadHTML omits the default XHTML ns (#26008).
        $namespaceUri = null;
        if (self::isLivingHtmlDocument($ownerDocument)) {
            $name = strtolower($name);
            if (!DomRegistry::state($ownerDocument)->htmlNoDefaultNs) {
                $namespaceUri = VmDomLiving::HTML_NS;
            }
        }
        $class = self::resolveElementClassForNamespace($ctx, $ownerDocument, $namespaceUri);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::writeElementNameSlots($entry, $name);
        self::initElementPropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $name;
        $state->localName = $name;
        $state->prefix = null;
        $state->namespaceUri = $namespaceUri;
        if (null !== $namespaceUri) {
            // Default HTML nsDef (php-src ensure_html_ns / #21030).
            $state->namespaceDeclarations[''] = $namespaceUri;
        }
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);
        if ('' !== $value) {
            self::writeTextContent($ctx, $entry, $value);
        }
        if (CompilerVersion::supportsDomTokenList()) {
            self::syncElementClassList($ctx, $entry);
        }
        self::ensureChildNodesList($ctx, $entry);
        self::ensureElementAttributesMap($ctx, $entry);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createElementNS(
        Context $ctx,
        ?string $namespace,
        string $qualifiedName,
        ?ObjectEntry $ownerDocument = null,
        string $value = ''
    ): Variable {
        // php-src document.c — QName + xml/xmlns namespace URI rules (#20594).
        // Non-strict: php_dom_throw_error → warning + RETURN_FALSE (#24804).
        $nsError = self::elementNSNameValidationError($namespace, $qualifiedName);
        if (null !== $nsError) {
            self::phpDomThrowError(
                $ctx,
                $ownerDocument,
                DomExceptionConstants::NAMESPACE_ERR,
                $nsError,
                'DOMDocument::createElementNS()'
            );

            return self::falseBoolVariable();
        }
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        // php-src php_dom.c dom_get_element_ce — HTMLElement only in HTML ns (#21030).
        $class = self::resolveElementClassForNamespace($ctx, $ownerDocument, $namespace);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::writeElementNameSlots($entry, $qualifiedName);
        self::initElementPropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $qualifiedName;
        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
        $state->namespaceUri = $namespace;
        // libxml nsDef on createElementNS — not a NamedNodeMap attribute (php-src; #19397).
        if (null !== $namespace) {
            $state->namespaceDeclarations['' !== $prefix ? $prefix : ''] = $namespace;
        }
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);
        if ('' !== $value) {
            self::writeTextContent($ctx, $entry, $value);
        }
        if (CompilerVersion::supportsDomTokenList()) {
            self::syncElementClassList($ctx, $entry);
        }
        self::ensureChildNodesList($ctx, $entry);
        self::ensureElementAttributesMap($ctx, $entry);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * DOMDocument::createAttributeNS() — requires a document element (php-src ext/dom/document.c; #19200).
     *
     * @return Variable DOMAttr or false when the document has no root element
     */
    public static function documentCreateAttributeNS(
        Context $ctx,
        ObjectEntry $document,
        ?string $namespace,
        string $qualifiedName,
        ?Frame $frame = null
    ): Variable {
        $root = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_NULL === $root->type) {
            self::triggerDomWarning($frame, 'DOMDocument::createAttributeNS(): Document Missing Root Element');
            $false = new Variable(Variable::TYPE_BOOLEAN);
            $false->bool(false);

            return $false;
        }

        return self::createAttributeNS($ctx, $namespace, $qualifiedName, $document);
    }

    public static function createAttributeNS(
        Context $ctx,
        ?string $namespace,
        string $qualifiedName,
        ?ObjectEntry $ownerDocument = null,
        bool $validateAsQName = true
    ): Variable {
        // createAttribute() uses Name rules; createAttributeNS() uses QName/NS rules (#20594).
        // Non-strict: php_dom_throw_error → warning + RETURN_FALSE (#24804).
        if ($validateAsQName) {
            $nsError = self::elementNSNameValidationError($namespace, $qualifiedName);
            if (null !== $nsError) {
                self::phpDomThrowError(
                    $ctx,
                    $ownerDocument,
                    DomExceptionConstants::NAMESPACE_ERR,
                    $nsError,
                    'DOMDocument::createAttributeNS()'
                );

                return self::falseBoolVariable();
            }
            [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        } else {
            // Non-NS Attr keeps the full XML Name (php-src attr->name), including leading ':'.
            $prefix = '';
            $localName = $qualifiedName;
        }
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ATTR);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        // php-src attr.c: living Attr.name is QName (#26024); legacy DOMAttr.name is local (#19754).
        $nameProp = VmDomLiving::isLivingAttr($entry) ? $qualifiedName : $localName;
        $entry->getProperty(self::PROP_NAME)->string($nameProp);
        $entry->getProperty(self::PROP_VALUE)->string('');
        $entry->getProperty(self::PROP_OWNER_ELEMENT)->null();
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ATTRIBUTE_NODE;
        $state->nodeName = $qualifiedName;
        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
        $state->namespaceUri = $namespace;
        $state->textContent = '';
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createAttribute(
        Context $ctx,
        string $name,
        ?ObjectEntry $ownerDocument = null
    ): Variable {
        // php-src xmlValidateName — Invalid Character Error (not Namespace Error) (#20594).
        // Non-strict: php_dom_throw_error → warning + RETURN_FALSE (#24804).
        if (!self::isValidXmlName($name)) {
            self::phpDomThrowError(
                $ctx,
                $ownerDocument,
                DomExceptionConstants::INVALID_CHARACTER_ERR,
                'Invalid Character Error',
                'DOMDocument::createAttribute()'
            );

            return self::falseBoolVariable();
        }

        return self::createAttributeNS($ctx, null, $name, $ownerDocument, false);
    }

    /**
     * Materialize a DOMNameSpaceNode for XPath namespace axis (php-src ext/dom/php_dom.c; #20097).
     *
     * @param string $prefix empty string for the default xmlns declaration
     */
    public static function createNameSpaceNode(
        Context $ctx,
        ObjectEntry $parentElement,
        string $prefix,
        string $namespaceUri
    ): ObjectEntry {
        $class = $ctx->classes[self::CLASS_NAMESPACE_NODE] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMNameSpaceNode is not registered in this compiler build');
        }

        $isDefault = '' === $prefix;
        $nodeName = $isDefault ? 'xmlns' : 'xmlns:'.$prefix;
        $localName = $isDefault ? 'xmlns' : $prefix;

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($nodeName);
        $entry->getProperty(self::PROP_PREFIX)->string($prefix);
        $entry->getProperty(self::PROP_LOCAL_NAME)->string($localName);
        $entry->getProperty(self::PROP_NAMESPACE_URI)->string($namespaceUri);
        $entry->getProperty(self::PROP_NODE_VALUE)->string($namespaceUri);
        $entry->getProperty(self::PROP_NODE_TYPE)->int(DomConstants::XML_NAMESPACE_DECL_NODE);
        $entry->getProperty(self::PROP_PARENT_NODE)->object($parentElement);
        $owner = self::ownerDocumentEntry($parentElement);
        if (null === $owner) {
            $entry->getProperty(self::PROP_OWNER_DOCUMENT)->null();
        } else {
            $entry->getProperty(self::PROP_OWNER_DOCUMENT)->object($owner);
        }

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NAMESPACE_DECL_NODE;
        $state->nodeName = $nodeName;
        $state->localName = $localName;
        $state->prefix = $isDefault ? null : $prefix;
        $state->namespaceUri = $namespaceUri;
        $state->textContent = $namespaceUri;
        $state->parentId = $parentElement->id;
        if (null !== $owner) {
            $state->documentId = $owner->id;
        }
        DomRegistry::attach($entry, $state);

        return $entry;
    }

    /**
     * Presence probe for nested AOT (#19507): returns $element if attribute exists,
     * otherwise returns owner document (always non-null ObjectEntry).
     */
    public static function attributePresenceProbe(ObjectEntry $element, string $qualifiedName): ObjectEntry
    {
        if (0 !== self::hasAttributeNameInt($element, $qualifiedName)) {
            return $element;
        }
        $owner = self::ownerDocumentEntry($element);
        if (null !== $owner) {
            return $owner;
        }

        return $element;
    }

    /** Nullable Attr for nested AOT helpers (#19507) — ObjectEntry|null crosses helper edge; int does not. */
    public static function getAttributeNodeOrNull(Context $ctx, ObjectEntry $element, string $name): ?ObjectEntry
    {
        $var = self::getAttributeNode($ctx, $element, $name);
        if (Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }

        return $var->toObject();
    }

    public static function getAttributeNode(Context $ctx, ObjectEntry $element, string $name): Variable
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        if (!\array_key_exists($name, $state->attributes)) {
            // Living Dom\* follow_spec → null; legacy DOMElement → false (php-src element.c; #26062).
            $var = new Variable();
            if (VmDomLiving::isLivingElement($element)) {
                $var->null();
            } else {
                $var->bool(false);
            }

            return $var;
        }
        $attr = self::attributeNodeForElement($ctx, $element, $name, $state->attributes[$name]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($attr);

        return $var;
    }

    public static function setAttributeNode(Context $ctx, ObjectEntry $element, ObjectEntry $attr): Variable
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (!self::isAttr($attr)) {
            throw new \TypeError('DOMElement::setAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        // php-src ext/dom/element.c — WRONG_DOCUMENT_ERR before adopt/move (#22709).
        self::assertSameDocument($element, $attr);
        $attrState = DomRegistry::state($attr);
        $name = $attrState->nodeName;
        $value = $attrState->textContent ?? '';
        // php-src xmlAddChild / setAttributeNode: moving an Attr detaches it from the previous owner.
        self::detachAttributeFromPreviousOwner($ctx, $attr, $element);
        $elementState = DomRegistry::state($element);
        $replaced = null;
        $previousIdValue = $elementState->attributes[$name] ?? null;
        $idBearing = self::elementAttributeIsIdBearing($element, $name);
        if (\array_key_exists($name, $elementState->attributes)) {
            $cachedId = $elementState->attributeNodeIds[$name] ?? null;
            if (null !== $cachedId) {
                $cached = DomRegistry::entry($cachedId);
                if (null !== $cached && $cached->id !== $attr->id) {
                    $replaced = $cached;
                    self::detachAttributeNode($replaced);
                }
            }
        }
        $elementState->attributes[$name] = $value;
        $elementState->attributeNamespaces[$name] = $attrState->namespaceUri ?? '';
        $elementState->attributeNodeIds[$name] = $attr->id;
        $attrState->ownerElementId = $element->id;
        $attrState->parentId = $element->id;
        $attr->getProperty(self::PROP_OWNER_ELEMENT)->object($element);
        self::ensureNamespaceDeclarationForPrefixedAttribute(
            $element,
            $attrState->prefix,
            $attrState->namespaceUri
        );
        // php-src setAttributeNode drops the old ID table entry but does not re-register (#19870).
        self::rebindElementIdOnAttributeWrite($element, $name, $previousIdValue, false, $idBearing);
        self::syncElementAttributes($ctx, $element);
        $var = new Variable();
        if (null === $replaced) {
            $var->null();
        } else {
            $var->object($replaced);
        }

        return $var;
    }

    /**
     * DOMElement::getAttributeNodeNS() — null when missing (php-src ext/dom/element.c; #19265).
     */
    public static function getAttributeNodeNS(
        Context $ctx,
        ObjectEntry $element,
        ?string $namespace,
        string $localName
    ): Variable {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $qName = self::findAttributeQNameByNsAndLocal($element, $namespace, $localName);
        $var = new Variable();
        if (null === $qName) {
            $var->null();

            return $var;
        }
        $state = DomRegistry::state($element);
        $attr = self::attributeNodeForElement($ctx, $element, $qName, $state->attributes[$qName]);
        $var->object($attr);

        return $var;
    }

    /**
     * DOMElement::setAttributeNodeNS() — replace by namespaceURI + localName (php-src ext/dom/element.c; #19265).
     */
    public static function setAttributeNodeNS(Context $ctx, ObjectEntry $element, ObjectEntry $attr): Variable
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (!self::isAttr($attr)) {
            throw new \TypeError('DOMElement::setAttributeNodeNS(): Argument #1 ($attr) must be of type DOMAttr');
        }
        // Guard before cross-name detach so foreign Attr cannot mutate the element (#22709).
        self::assertSameDocument($element, $attr);
        $attrState = DomRegistry::state($attr);
        $localName = $attrState->localName ?? $attrState->nodeName;
        $namespace = $attrState->namespaceUri;
        $nsArg = (null === $namespace || '' === $namespace) ? null : $namespace;
        $existingQName = self::findAttributeQNameByNsAndLocal($element, $nsArg, $localName);
        $crossNameReplaced = null;
        if (null !== $existingQName && $existingQName !== $attrState->nodeName) {
            $elementState = DomRegistry::state($element);
            $cachedId = $elementState->attributeNodeIds[$existingQName] ?? null;
            if (null !== $cachedId) {
                $cached = DomRegistry::entry($cachedId);
                if (null !== $cached && $cached->id !== $attr->id) {
                    $crossNameReplaced = $cached;
                    self::detachAttributeNode($cached);
                }
            }
            unset(
                $elementState->attributes[$existingQName],
                $elementState->attributeNamespaces[$existingQName],
                $elementState->attributeNodeIds[$existingQName]
            );
            if (null !== $elementState->idAttributeName && $existingQName === $elementState->idAttributeName) {
                $elementState->idAttributeName = $attrState->nodeName;
            }
        }
        $result = self::setAttributeNode($ctx, $element, $attr);
        if (null !== $crossNameReplaced) {
            $var = new Variable();
            $var->object($crossNameReplaced);

            return $var;
        }

        return $result;
    }

    public static function removeAttributeNode(Context $ctx, ObjectEntry $element, ObjectEntry $attr): Variable
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (!self::isAttr($attr)) {
            throw new \TypeError('DOMElement::removeAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attrState = DomRegistry::state($attr);
        $name = $attrState->nodeName;
        $elementState = DomRegistry::state($element);
        $cachedId = $elementState->attributeNodeIds[$name] ?? null;
        if (!\array_key_exists($name, $elementState->attributes)
            || $attrState->ownerElementId !== $element->id
            || (null !== $cachedId && $cachedId !== $attr->id)
        ) {
            DomExceptionConstants::raiseNotFound();
        }
        $previousIdValue = $elementState->attributes[$name] ?? null;
        $idBearing = self::elementAttributeIsIdBearing($element, $name);
        unset($elementState->attributes[$name], $elementState->attributeNamespaces[$name], $elementState->attributeNodeIds[$name]);
        self::detachAttributeNode($attr);
        self::syncSubtree($ctx, $attr);
        self::rebindElementIdOnAttributeWrite($element, $name, $previousIdValue, false, $idBearing);
        self::syncElementAttributes($ctx, $element);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($attr);

        return $var;
    }

    private static function attributeNodeForElement(
        Context $ctx,
        ObjectEntry $element,
        string $name,
        string $value
    ): ObjectEntry {
        $state = DomRegistry::state($element);
        [$prefix] = self::splitQualifiedName($name);
        $resolvedNs = self::resolveAttributeNamespaceUri($element, $name, $prefix);
        $cachedId = $state->attributeNodeIds[$name] ?? null;
        if (null !== $cachedId) {
            $cached = DomRegistry::entry($cachedId);
            if (null !== $cached && self::isAttr($cached)) {
                $cachedNs = DomRegistry::state($cached)->namespaceUri ?? '';
                // Parent xmlns may resolve only after the tree is linked (#20615).
                if ($cachedNs === $resolvedNs
                    || ('' === $resolvedNs && '' === $cachedNs)
                ) {
                    self::syncAttributeNodeValue($ctx, $cached, $value, true);
                    $cachedState = DomRegistry::state($cached);
                    $cachedState->ownerElementId = $element->id;
                    $cachedState->parentId = $element->id;
                    $cached->getProperty(self::PROP_OWNER_ELEMENT)->object($element);

                    return $cached;
                }
                unset($state->attributeNodeIds[$name]);
            }
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        // Declared / xml:* → QName+NS Attr. Undeclared prefix → libxml recovery Name (#20615).
        if ('' !== $prefix && '' !== $resolvedNs) {
            $state->attributeNamespaces[$name] = $resolvedNs;
            $attrVar = self::createAttributeNS($ctx, $resolvedNs, $name, $ownerDocument, true);
        } else {
            if ('' === $prefix) {
                $state->attributeNamespaces[$name] = '';
            } else {
                // Leave unset so a later parent xmlns can still resolve.
                unset($state->attributeNamespaces[$name]);
            }
            $attrVar = self::createAttributeNS($ctx, null, $name, $ownerDocument, false);
        }
        $attr = $attrVar->toObject();
        self::syncAttributeNodeValue($ctx, $attr, $value, true);
        $attrState = DomRegistry::state($attr);
        $attrState->ownerElementId = $element->id;
        $attrState->parentId = $element->id;
        $attr->getProperty(self::PROP_OWNER_ELEMENT)->object($element);
        $state->attributeNodeIds[$name] = $attr->id;

        return $attr;
    }

    /**
     * Keep Attr::$value / nodeValue and the libxml-style value text child in sync
     * (php-src ext/dom/attr.c; #20501).
     *
     * @param bool $ensureTextChild Create a DOMText child even for empty values (element-attached
     *                              attrs and user writes). Fresh createAttribute() leaves no child.
     */
    private static function syncAttributeNodeValue(
        Context $ctx,
        ObjectEntry $attr,
        string $value,
        bool $ensureTextChild = false
    ): void {
        $attrState = DomRegistry::state($attr);
        $attrState->textContent = $value;
        if ($attr->hasProperty(self::PROP_VALUE)) {
            $attr->getProperty(self::PROP_VALUE)->string($value);
        }
        if ($attr->hasProperty(self::PROP_NODE_VALUE)) {
            $attr->getProperty(self::PROP_NODE_VALUE)->string($value);
        }
        $hasChildren = [] !== $attrState->childIds;
        if ($ensureTextChild || $hasChildren || '' !== $value) {
            self::ensureAttrValueTextChild($ctx, $attr, $value);
        } else {
            self::syncSubtree($ctx, $attr);
        }
    }

    /** Materialize / refresh Attr value text node (libxml xmlAttr->children; #20501). */
    private static function ensureAttrValueTextChild(Context $ctx, ObjectEntry $attr, string $value): void
    {
        $attrState = DomRegistry::state($attr);
        $ownerDocument = self::ownerDocumentEntry($attr);
        if ([] !== $attrState->childIds) {
            $existing = DomRegistry::entry($attrState->childIds[0]);
            if (null !== $existing && self::isTextNode($existing)) {
                DomRegistry::state($existing)->textContent = $value;
                // Drop any extra children; php-src Attr value write replaces content.
                $attrState->childIds = [$existing->id];
                self::linkChildToParent($existing, $attr);
                self::syncSubtree($ctx, $attr);

                return;
            }
            $attrState->childIds = [];
        }
        $text = self::createTextNode($ctx, $value, $ownerDocument);
        $attrState->childIds = [$text->id];
        self::linkChildToParent($text, $attr);
        self::syncSubtree($ctx, $attr);
    }

    private static function detachAttributeNode(ObjectEntry $attr): void
    {
        $attrState = DomRegistry::state($attr);
        $attrState->ownerElementId = null;
        $attrState->parentId = null;
        if ($attr->hasProperty(self::PROP_OWNER_ELEMENT)) {
            $attr->getProperty(self::PROP_OWNER_ELEMENT)->null();
        }
        // Clear parent/sibling slots; keep value text children (php-src xmlUnlinkNode on Attr; #20501).
        self::clearDetachedNodeLinkProperties($attr);
    }

    /**
     * Remove Attr from a previous owner element when relocating (php-src ext/dom/node.c xmlAddChild).
     */
    private static function detachAttributeFromPreviousOwner(
        Context $ctx,
        ObjectEntry $attr,
        ObjectEntry $newOwner
    ): void {
        $attrState = DomRegistry::state($attr);
        $prevOwnerId = $attrState->ownerElementId;
        if (null === $prevOwnerId || $prevOwnerId === $newOwner->id) {
            return;
        }
        $prevOwner = DomRegistry::entry($prevOwnerId);
        if (null === $prevOwner || !self::isElement($prevOwner)) {
            self::detachAttributeNode($attr);

            return;
        }
        $prevState = DomRegistry::state($prevOwner);
        foreach ($prevState->attributeNodeIds as $qName => $cachedId) {
            if ($cachedId !== $attr->id) {
                continue;
            }
            $previousIdValue = $prevState->attributes[$qName] ?? null;
            $idBearing = self::elementAttributeIsIdBearing($prevOwner, $qName);
            unset(
                $prevState->attributes[$qName],
                $prevState->attributeNamespaces[$qName],
                $prevState->attributeNodeIds[$qName]
            );
            self::rebindElementIdOnAttributeWrite($prevOwner, $qName, $previousIdValue, false, $idBearing);
            self::syncElementAttributes($ctx, $prevOwner);
            break;
        }
        self::detachAttributeNode($attr);
    }

    /**
     * DOMElement::getAttributeNS() — living Dom\* missing → null; legacy → ''
     * (php-src element.c php_dom_follow_spec_intern; #26062).
     */
    public static function getAttributeNS(ObjectEntry $element, ?string $namespace, string $localName): ?string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = self::resolveAttributeNamespaceUri($element, $qName, $prefix);
            if ($attrNs === $wantNs) {
                return $value;
            }
        }

        return VmDomLiving::isLivingElement($element) ? null : '';
    }

    public static function hasAttributeNS(ObjectEntry $element, ?string $namespace, string $localName): bool
    {
        return self::hasAttributeNSExact($element, $namespace, $localName);
    }

    private static function hasAttributeNSExact(ObjectEntry $element, ?string $namespace, string $localName): bool
    {
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = self::resolveAttributeNamespaceUri($element, $qName, $prefix);

            return $attrNs === $wantNs;
        }

        return false;
    }

    /**
     * DOMElement::getAttributeNames() — attribute qNames in document order (php-src ext/dom/element.c; #16823).
     */
    public static function getAttributeNames(ObjectEntry $element): HashTable
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);

        return VmFs::stringListToArray(array_keys($state->attributes));
    }

    /**
     * DOMElement::getInnerHTML() / Dom\Element::$innerHTML — serialize child nodes
     * (php-src ext/dom/inner_html_mixin.c; #16916, #22773).
     *
     * HTML documents use the HTML serializer (same empty/void rules as saveHTML);
     * XML documents keep XML empty-element form (`<i/>`).
     * HTML `<template>` serializes its templated contents fragment (#26034).
     */
    public static function getInnerHTML(ObjectEntry $element): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $childIds = self::elementSerializationChildIds($element);
        if ([] === $childIds) {
            return '';
        }
        // HTML doc → no XML self-close; XML doc → `<tag/>` (#22773 / php-src inner_html_mixin.c).
        $emptySelfClosing = !self::elementOwnerIsHtmlDocument($element);
        $parts = [];
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeHtmlNode($child, $emptySelfClosing);
            }
        }

        return implode('', $parts);
    }

    /**
     * DOMElement::getOuterHTML() / Dom\Element::$outerHTML — serialize element and descendants
     * (php-src ext/dom/inner_html_mixin.c; #16916, #22773).
     */
    public static function getOuterHTML(ObjectEntry $element): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }

        $emptySelfClosing = !self::elementOwnerIsHtmlDocument($element);

        return self::serializeHtmlElement($element, $emptySelfClosing);
    }

    /**
     * Owner is Dom\HTMLDocument or legacy DOMDocument after loadHTML
     * (php-src: context_document->type == XML_HTML_DOCUMENT_NODE; #22773).
     */
    private static function elementOwnerIsHtmlDocument(ObjectEntry $element): bool
    {
        $owner = self::ownerDocumentEntry($element);
        if (null === $owner || !self::isDocument($owner)) {
            return false;
        }
        if (self::isLivingHtmlDocument($owner)) {
            return true;
        }

        return DomRegistry::state($owner)->isHtmlDocument;
    }

    /**
     * Dom\Element::$innerHTML setter — replace children with parsed fragment
     * (php-src ext/dom/inner_outer_html_mixin.c; #20532).
     *
     * HTML `<template>` writes into the templated contents DocumentFragment (#26034).
     */
    public static function setInnerHTML(Context $ctx, ObjectEntry $element, string $html): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        if (null === $ownerDocument) {
            throw new \DOMException('Hierarchy request error');
        }
        $fragment = self::parseHtmlIntoFragment($ctx, $html, $ownerDocument);
        if (self::isHtmlTemplateElement($element)) {
            $content = self::ensureTemplateContentFragment($ctx, $element);
            self::removeAllLiveStandardChildren($ctx, $content);
            self::appendLiveStandardChild($ctx, $content, $fragment);
            self::syncSubtree($ctx, $content);

            return;
        }
        self::removeAllLiveStandardChildren($ctx, $element);
        self::appendLiveStandardChild($ctx, $element, $fragment);
        self::syncSubtree($ctx, $element);
    }

    /**
     * Dom\Element::$outerHTML setter — replace element with parsed fragment
     * (php-src ext/dom/inner_outer_html_mixin.c; #20532).
     */
    public static function setOuterHTML(Context $ctx, ObjectEntry $element, string $html): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        if (null === $state->parentId) {
            // Spec: if parent is null, return (no-op).
            return;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            return;
        }
        if (self::isDocument($parent) || VmDomLiving::isLivingDocument($parent)) {
            throw new \DOMException(
                'Invalid Modification Error',
                DomExceptionConstants::INVALID_MODIFICATION_ERR
            );
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        if (null === $ownerDocument) {
            throw new \DOMException('Hierarchy request error');
        }
        $fragment = self::parseHtmlIntoFragment($ctx, $html, $ownerDocument);
        self::insertBeforeSibling($ctx, $parent, $fragment, $element);
        self::removeChild($ctx, $parent, $element);
        self::syncSubtree($ctx, $parent);
    }

    /**
     * Dom\Element::$substitutedNodeValue getter — xmlNodeGetContent (entity substitution)
     * (php-src ext/dom/element.c dom_modern_element_substituted_node_value_read; #21034).
     */
    public static function getSubstitutedNodeValue(ObjectEntry $element): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }

        return self::readTextContent($element);
    }

    /**
     * Dom\Element::$substitutedNodeValue setter — xmlNodeSetContentLen entity parse
     * (php-src ext/dom/element.c dom_modern_element_substituted_node_value_write; #21034).
     */
    public static function setSubstitutedNodeValue(Context $ctx, ObjectEntry $element, string $value): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $unterminated = self::xmlContentUnterminatedEntityName($value);
        self::removeAllLiveStandardChildren($ctx, $element);
        if (null !== $unterminated) {
            $ctx->errors->triggerError(
                'unterminated entity reference '.$unterminated,
                ErrorReporter::E_WARNING,
                null,
                $ctx
            );
            self::syncSubtree($ctx, $element);

            return;
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        $generalEntities = null !== $ownerDocument
            ? DomRegistry::state($ownerDocument)->generalEntities
            : [];
        self::appendXmlNodeSetContentChildren(
            $ctx,
            $element,
            $value,
            $ownerDocument,
            $generalEntities
        );
        self::syncSubtree($ctx, $element);
        VmDomSimpleXmlBridge::syncSimpleXmlTextFromDom($element, self::readTextContent($element));
    }

    /**
     * Detect libxml unterminated entity refs in xmlNodeSetContentLen input (#21034).
     *
     * @return null|string null when well-formed; entity name (may be '') when unterminated
     */
    private static function xmlContentUnterminatedEntityName(string $text): ?string
    {
        $pos = 0;
        $len = \strlen($text);
        while ($pos < $len) {
            $amp = strpos($text, '&', $pos);
            if (false === $amp) {
                return null;
            }
            $semi = strpos($text, ';', $amp + 1);
            if (false === $semi) {
                return substr($text, $amp + 1);
            }
            $pos = $semi + 1;
        }

        return null;
    }

    /**
     * Approximate libxml xmlStringGetNodeList for xmlNodeSetContentLen (#21034).
     *
     * @param array<string, string> $generalEntities
     */
    private static function appendXmlNodeSetContentChildren(
        Context $ctx,
        ObjectEntry $parent,
        string $text,
        ?ObjectEntry $ownerDocument,
        array $generalEntities
    ): void {
        if ('' === $text) {
            return;
        }
        $state = DomRegistry::state($parent);
        $pos = 0;
        $len = \strlen($text);
        $buffer = '';
        while ($pos < $len) {
            $amp = strpos($text, '&', $pos);
            if (false === $amp) {
                $buffer .= substr($text, $pos);
                break;
            }
            if ($amp > $pos) {
                $buffer .= substr($text, $pos, $amp - $pos);
            }
            $semi = strpos($text, ';', $amp + 1);
            // Unterminated refs are rejected before this helper runs.
            if (false === $semi) {
                $buffer .= substr($text, $amp);
                break;
            }
            $refName = substr($text, $amp + 1, $semi - $amp - 1);
            $decoded = self::decodeXmlContentEntityRef($refName);
            if (null !== $decoded) {
                $buffer .= $decoded;
            } elseif ('' === $refName || str_starts_with($refName, '#')) {
                // Empty / broken numeric refs contribute nothing (libxml quirks).
            } else {
                if ('' !== $buffer) {
                    $textNode = self::createTextNode($ctx, $buffer, $ownerDocument);
                    $state->childIds[] = $textNode->id;
                    self::linkChildToParent($textNode, $parent);
                    $buffer = '';
                }
                $replacement = $generalEntities[$refName] ?? '';
                $entityRef = self::createEntityReferenceFromLoad(
                    $ctx,
                    $refName,
                    $replacement,
                    $ownerDocument
                );
                $state->childIds[] = $entityRef->id;
                self::linkChildToParent($entityRef, $parent);
            }
            $pos = $semi + 1;
        }
        if ('' !== $buffer) {
            $textNode = self::createTextNode($ctx, $buffer, $ownerDocument);
            $state->childIds[] = $textNode->id;
            self::linkChildToParent($textNode, $parent);
        }
    }

    /**
     * Decode predefined / numeric character references for xmlNodeSetContentLen (#21034).
     *
     * @return null|string null when the ref should become an EntityReference (named)
     */
    private static function decodeXmlContentEntityRef(string $refName): ?string
    {
        if ('' === $refName) {
            return '';
        }
        $predefined = self::decodePredefinedXmlEntity($refName);
        if (null !== $predefined) {
            return $predefined;
        }
        if (!str_starts_with($refName, '#')) {
            return null;
        }
        $body = substr($refName, 1);
        if ('' === $body) {
            return '';
        }
        if ('x' === $body[0] || 'X' === $body[0]) {
            $hex = substr($body, 1);
            if ('' === $hex || 1 !== preg_match('/^[0-9A-Fa-f]+$/', $hex)) {
                return '';
            }
            $codepoint = hexdec($hex);
        } else {
            if (1 !== preg_match('/^[0-9]+$/', $body)) {
                return '';
            }
            $codepoint = (int) $body;
        }
        if ($codepoint <= 0 || $codepoint > 0x10FFFF) {
            return '';
        }
        if (\function_exists('mb_chr')) {
            $char = \mb_chr($codepoint, 'UTF-8');
            if (false !== $char && null !== $char) {
                return $char;
            }
        }
        // BMP fallback without mbstring.
        if ($codepoint <= 0x7F) {
            return \chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return \chr(0xC0 | ($codepoint >> 6)).\chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return \chr(0xE0 | ($codepoint >> 12))
                .\chr(0x80 | (($codepoint >> 6) & 0x3F))
                .\chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18))
            .\chr(0x80 | (($codepoint >> 12) & 0x3F))
            .\chr(0x80 | (($codepoint >> 6) & 0x3F))
            .\chr(0x80 | ($codepoint & 0x3F));
    }

    /**
     * DOMElement::setAttribute() — php-src element.c returns the Attr via DOM_RET_OBJ;
     * literal xmlns installs a nsDef and returns true (#24538).
     *
     * Name validation always uses strict=1 (php-src element.c; #24804) — unlike create*.
     */
    public static function setAttribute(
        Context $ctx,
        ObjectEntry $element,
        string $name,
        string $value
    ): Variable {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        // php-src: xmlNewNs for name=="xmlns" → RETURN_TRUE (not Attr).
        if ('xmlns' === $name) {
            self::setAttributeNS($ctx, $element, null, $name, $value, false);
            $var = new Variable();
            $var->bool(true);

            return $var;
        }
        // Always-strict xmlValidateName (php-src passes 1 to php_dom_throw_error; #24804).
        if (!self::isValidXmlName($name)) {
            throw new \DOMException(
                'Invalid Character Error',
                DomExceptionConstants::INVALID_CHARACTER_ERR
            );
        }
        // Skip QName re-check — Name rules already applied; leading ':' is a valid Name (#24804).
        self::setAttributeNS($ctx, $element, null, $name, $value, false);

        return self::getAttributeNode($ctx, $element, $name);
    }

    /**
     * @param bool $validateQName when false (setAttribute path), skip QName/NS checks
     */
    public static function setAttributeNS(
        Context $ctx,
        ObjectEntry $element,
        ?string $namespace,
        string $qualifiedName,
        string $value,
        bool $validateQName = true
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        // Real xmlns / xmlns:* nsDef — not Attr map entries (php-src element.c / nodemap.c; #19718).
        if ('xmlns' === $qualifiedName
            && (null === $namespace || '' === $namespace || self::isXmlnsNamespaceUri($namespace))) {
            $state->namespaceDeclarations[''] = $value;
            $state->xmlnsAttributePrefixes[''] = true;
            self::detachCachedAttributeIfAny($state, 'xmlns');
            unset($state->attributes['xmlns'], $state->attributeNamespaces['xmlns']);
            self::syncElementAttributes($ctx, $element);

            return;
        }
        if (str_starts_with($qualifiedName, 'xmlns:') && self::isXmlnsNamespaceUri($namespace)) {
            $prefix = substr($qualifiedName, 6);
            $state->namespaceDeclarations[$prefix] = $value;
            $state->xmlnsAttributePrefixes[$prefix] = true;
            self::detachCachedAttributeIfAny($state, $qualifiedName);
            unset($state->attributes[$qualifiedName], $state->attributeNamespaces[$qualifiedName]);
            self::syncElementAttributes($ctx, $element);

            return;
        }
        // php-src element.c: setAttributeNS uses document strictErrorChecking (#24804).
        if ($validateQName) {
            $nsError = self::elementNSNameValidationError($namespace, $qualifiedName);
            if (null !== $nsError) {
                self::phpDomThrowError(
                    $ctx,
                    self::ownerDocumentEntry($element),
                    DomExceptionConstants::NAMESPACE_ERR,
                    $nsError,
                    'DOMElement::setAttributeNS()'
                );

                return;
            }
        }
        $attrExisted = \array_key_exists($qualifiedName, $state->attributes);
        $previousIdValue = $attrExisted ? ($state->attributes[$qualifiedName] ?? null) : null;
        // Apply namespace before the ID-bearing check so xml:id / namespaced id is recognized (#19870).
        $state->attributeNamespaces[$qualifiedName] = $namespace ?? '';
        $idBearing = self::elementAttributeIsIdBearing($element, $qualifiedName);
        // HTML htmlSetProp: creating a *new* id attr stamps XML_ATTRIBUTE_ID. Value rewrite of a
        // non-ID imported id must not promote (php-src / libxml; #23514).
        if (!$idBearing && !$attrExisted && 'id' === $qualifiedName) {
            $ownerDoc = self::ownerDocumentEntry($element);
            if (null !== $ownerDoc && DomRegistry::state($ownerDoc)->isHtmlDocument) {
                $idBearing = true;
            }
        }
        $state->attributes[$qualifiedName] = $value;
        if (isset($state->attributeNodeIds[$qualifiedName])) {
            $cached = DomRegistry::entry($state->attributeNodeIds[$qualifiedName]);
            if (null !== $cached && self::isAttr($cached)) {
                self::syncAttributeNodeValue($ctx, $cached, $value, true);
            }
        }
        // Bogus Attr named xmlns:* (null NS) stays in the Attr map — do not promote to nsDef.
        if (!self::isXmlnsAttributeName($qualifiedName)) {
            [$attrPrefix] = self::splitQualifiedName($qualifiedName);
            self::ensureNamespaceDeclarationForPrefixedAttribute($element, '' !== $attrPrefix ? $attrPrefix : null, $namespace);
        }
        // HTML id / xml:id / setIdAttribute — refresh getElementById map (php-src element.c; #19870).
        self::rebindElementIdOnAttributeWrite($element, $qualifiedName, $previousIdValue, true, $idBearing);
        if (CompilerVersion::supportsDomTokenList() && 'class' === $qualifiedName) {
            VmDomTokenList::invalidateForElement($element);
        }
        self::syncElementAttributes($ctx, $element);
        // Live SimpleXML peer attribute map (#20137).
        VmDomSimpleXmlBridge::syncSimpleXmlAttributesFromDom($element);
    }

    private static function detachCachedAttributeIfAny(DomNodeState $state, string $qualifiedName): void
    {
        if (!isset($state->attributeNodeIds[$qualifiedName])) {
            return;
        }
        $cached = DomRegistry::entry($state->attributeNodeIds[$qualifiedName]);
        if (null !== $cached && self::isAttr($cached)) {
            self::detachAttributeNode($cached);
        }
        unset($state->attributeNodeIds[$qualifiedName]);
    }

    public static function removeAttributeNS(Context $ctx, ObjectEntry $element, ?string $namespace, string $localName): bool
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        $removedQName = null;
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = self::resolveAttributeNamespaceUri($element, $qName, $prefix);
            if ($attrNs === $wantNs) {
                $removedQName = $qName;
                break;
            }
        }
        if (null === $removedQName) {
            return false;
        }
        $previousIdValue = $state->attributes[$removedQName] ?? null;
        $idBearing = self::elementAttributeIsIdBearing($element, $removedQName);
        if (isset($state->attributeNodeIds[$removedQName])) {
            $cached = DomRegistry::entry($state->attributeNodeIds[$removedQName]);
            if (null !== $cached && self::isAttr($cached)) {
                self::detachAttributeNode($cached);
            }
            unset($state->attributeNodeIds[$removedQName]);
        }
        unset($state->attributes[$removedQName], $state->attributeNamespaces[$removedQName]);
        // Drop ID map entry before clearing setIdAttribute flag (#19870).
        self::rebindElementIdOnAttributeWrite($element, $removedQName, $previousIdValue, false, $idBearing);
        if (CompilerVersion::supportsDomTokenList() && 'class' === $removedQName) {
            VmDomTokenList::invalidateForElement($element);
        }
        self::syncElementAttributes($ctx, $element);
        VmDomSimpleXmlBridge::syncSimpleXmlAttributesFromDom($element);

        return true;
    }

    /**
     * DOMElement::toggleAttribute() — boolean attribute toggle (php-src ext/dom/element.c; #16824).
     */
    public static function toggleAttribute(
        Context $ctx,
        ObjectEntry $element,
        string $qualifiedName,
        ?bool $force
    ): bool {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        self::assertValidXmlName($qualifiedName);
        $qualifiedName = self::normalizeToggleAttributeQName($element, $qualifiedName);
        $has = self::hasAttributeByQName($element, $qualifiedName);
        if (!$has) {
            if (null === $force || $force) {
                self::setAttributeNS($ctx, $element, null, $qualifiedName, '');

                return true;
            }

            return false;
        }
        if (null === $force || !$force) {
            self::removeAttributeByQName($ctx, $element, $qualifiedName);

            return false;
        }

        return true;
    }

    /** Presence as int for nested AOT helpers (#19507). */
    public static function hasAttributeNameInt(ObjectEntry $element, string $qualifiedName): int
    {
        if (!self::isElement($element)) {
            return 0;
        }
        $qualifiedName = self::normalizeToggleAttributeQName($element, $qualifiedName);
        if (self::isXmlnsAttributeName($qualifiedName)) {
            return 0;
        }
        $state = DomRegistry::state($element);
        if (\array_key_exists($qualifiedName, $state->attributes)) {
            return 1;
        }

        return 0;
    }

    /**
     * Omit-force toggle returning int 0/1 for nested AOT helpers (#19507).
     * Keep DomRegistry access inside VmDom (not the nested helper TU).
     */
    public static function toggleAttributeOmitInt(Context $ctx, ObjectEntry $element, string $qualifiedName): int
    {
        self::toggleAttribute($ctx, $element, $qualifiedName, null);

        return self::hasAttributeNameInt($element, $qualifiedName);
    }

    /** DOMElement::setIdAttribute() — manual ID map for getElementById() (php-src ext/dom/node.c; #14493). */
    public static function setIdAttribute(ObjectEntry $element, string $name, bool $isId): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        if (!\array_key_exists($name, $state->attributes)) {
            DomExceptionConstants::raiseNotFound();
        }
        self::applyIdAttributeRegistration($element, $name, $isId);
    }

    /**
     * NestedJIT/user-script AOT: DomRegistry ID update without PROP_ELEMENT_ID_MAP sync (#29257).
     */
    public static function setIdAttributeWithoutIdMapSync(ObjectEntry $element, string $name, bool $isId): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        if (!\array_key_exists($name, $state->attributes)) {
            DomExceptionConstants::raiseNotFound();
        }
        self::applyIdAttributeRegistration($element, $name, $isId, false);
    }

    /** DOMElement::setIdAttributeNS() — namespaced ID map (php-src ext/dom/element.c; #15300). */
    public static function setIdAttributeNS(
        ObjectEntry $element,
        ?string $namespace,
        string $localName,
        bool $isId
    ): void {
        self::setIdAttributeNSInternal($element, $namespace, $localName, $isId, true);
    }

    /**
     * NestedJIT/user-script AOT: DomRegistry ID update without PROP_ELEMENT_ID_MAP sync (#29284).
     */
    public static function setIdAttributeNSWithoutIdMapSync(
        ObjectEntry $element,
        ?string $namespace,
        string $localName,
        bool $isId
    ): void {
        self::setIdAttributeNSInternal($element, $namespace, $localName, $isId, false);
    }

    private static function setIdAttributeNSInternal(
        ObjectEntry $element,
        ?string $namespace,
        string $localName,
        bool $isId,
        bool $syncIdMap
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $qName = self::findAttributeQNameByNsAndLocal($element, $namespace, $localName);
        if (null === $qName) {
            DomExceptionConstants::raiseNotFound();
        }
        self::applyIdAttributeRegistration($element, $qName, $isId, $syncIdMap);
    }

    /**
     * DOMElement::setIdAttributeNode() — mark an owned Attr as ID (php-src ext/dom/element.c; #20123).
     * Zend: NOT_FOUND_ERR when attr->parent != element.
     */
    public static function setIdAttributeNode(ObjectEntry $element, ObjectEntry $attr, bool $isId): void
    {
        self::setIdAttributeNodeInternal($element, $attr, $isId, true);
    }

    /**
     * NestedJIT/user-script AOT: DomRegistry ID update without PROP_ELEMENT_ID_MAP sync (#29284).
     */
    public static function setIdAttributeNodeWithoutIdMapSync(
        ObjectEntry $element,
        ObjectEntry $attr,
        bool $isId
    ): void {
        self::setIdAttributeNodeInternal($element, $attr, $isId, false);
    }

    private static function setIdAttributeNodeInternal(
        ObjectEntry $element,
        ObjectEntry $attr,
        bool $isId,
        bool $syncIdMap
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (!self::isAttr($attr)) {
            throw new \TypeError('DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attrState = DomRegistry::state($attr);
        $name = $attrState->nodeName;
        $elementState = DomRegistry::state($element);
        $cachedId = $elementState->attributeNodeIds[$name] ?? null;
        if (!\array_key_exists($name, $elementState->attributes)
            || $attrState->ownerElementId !== $element->id
            || (null !== $cachedId && $cachedId !== $attr->id)
        ) {
            DomExceptionConstants::raiseNotFound();
        }
        self::applyIdAttributeRegistration($element, $name, $isId, $syncIdMap);
    }

    private static function applyIdAttributeRegistration(
        ObjectEntry $element,
        string $qName,
        bool $isId,
        bool $syncIdMap = true
    ): void {
        $state = DomRegistry::state($element);
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            DomExceptionConstants::raiseNotFound();
        }
        // php-src 8.2 php_set_attribute_id → xmlAddID / xmlRemoveID (ext/dom/element.c).
        // xmlAddID sets atype only on success; duplicate IDs leave isId false (#25274).
        // libxml keeps the ID table entry until the attribute/element is destroyed — not on
        // detach — so getElementById filters with php_dom_is_node_attached (#23999).
        if ($isId) {
            if ($state->idAttributeName === $qName) {
                // Already marked ID on this attr (atype == XML_ATTRIBUTE_ID) — no-op.
                if ($syncIdMap) {
                    self::syncElementIdMapProperty($document);
                }

                return;
            }
            self::unregisterElementId($document, $element);
            $state->idAttributeName = $qName;
            if (!self::registerElementId($document, $element)) {
                // xmlAddID failed (ID already defined by another attr) — do not leave atype set.
                $state->idAttributeName = null;
            }
        } else {
            self::unregisterElementId($document, $element);
            $state->idAttributeName = null;
        }
        if ($syncIdMap) {
            self::syncElementIdMapProperty($document);
        }
    }

    private static function findAttributeQNameByNsAndLocal(
        ObjectEntry $element,
        ?string $namespace,
        string $localName
    ): ?string {
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($element);
        foreach ($state->attributes as $qName => $value) {
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            [$prefix, $local] = self::splitQualifiedName($qName);
            if ($local !== $localName) {
                continue;
            }
            $attrNs = self::resolveAttributeNamespaceUri($element, $qName, $prefix);
            if ($attrNs === $wantNs) {
                return $qName;
            }
        }

        return null;
    }

    private static function resolveAttributeNamespaceUri(
        ObjectEntry $element,
        string $qName,
        string $prefix
    ): string {
        $state = DomRegistry::state($element);
        if (\array_key_exists($qName, $state->attributeNamespaces)) {
            return $state->attributeNamespaces[$qName];
        }
        if ('' === $prefix) {
            return '';
        }

        return self::lookupNamespaceURI($element, $prefix) ?? '';
    }

    /**
     * libxml xmlAddID — first registration wins; detached nodes stay in the table (#25274 / #25275).
     *
     * @return bool true when this element owns the ID slot after the call
     */
    private static function registerElementId(ObjectEntry $document, ObjectEntry $element): bool
    {
        $nodeState = DomRegistry::state($element);
        $docState = DomRegistry::state($document);
        $idAttr = self::resolveElementIdAttributeName($document, $docState, $nodeState);
        if (null === $idAttr) {
            return false;
        }
        $value = $nodeState->attributes[$idAttr] ?? null;
        if (null === $value || '' === $value) {
            return false;
        }
        // xmlHashAddEntry fails when the ID is already defined (even on a detached node).
        if (\array_key_exists($value, $docState->elementIds)) {
            return $docState->elementIds[$value] === $element->id;
        }
        $docState->elementIds[$value] = $element->id;

        return true;
    }

    /**
     * @param string|null $valueOverride Previous ID string when attributes already mutated (#19870).
     */
    private static function unregisterElementId(
        ObjectEntry $document,
        ObjectEntry $element,
        ?string $valueOverride = null
    ): void {
        if (null !== $valueOverride) {
            if ('' === $valueOverride) {
                return;
            }
            $docState = DomRegistry::state($document);
            if (($docState->elementIds[$valueOverride] ?? null) === $element->id) {
                unset($docState->elementIds[$valueOverride]);
            }

            return;
        }
        $nodeState = DomRegistry::state($element);
        $docState = DomRegistry::state($document);
        $idAttr = self::resolveElementIdAttributeName($document, $docState, $nodeState);
        if (null === $idAttr) {
            return;
        }
        $value = $nodeState->attributes[$idAttr] ?? null;
        if (null === $value || '' === $value) {
            return;
        }
        if (($docState->elementIds[$value] ?? null) === $element->id) {
            unset($docState->elementIds[$value]);
        }
    }

    private static function syncElementIdRegistration(ObjectEntry $element): void
    {
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            return;
        }
        self::unregisterElementId($document, $element);
        self::registerElementId($document, $element);
        self::syncElementIdMapProperty($document);
    }

    /**
     * Whether $qName is an ID-bearing attribute for this element (HTML id / xml:id / setIdAttribute / DTD).
     * Does not require the attribute to be present on the element (#19870).
     *
     * HTML `id` is ID-bearing only when stamped as XML_ATTRIBUTE_ID (HTML parse / new htmlSetProp /
     * import from an ID source). Plain XML `id` imported into HTML stays non-ID (#23514).
     */
    private static function attributeQNameIsIdBearing(
        ObjectEntry $document,
        DomNodeState $docState,
        DomNodeState $nodeState,
        string $qName
    ): bool {
        if (null !== $nodeState->idAttributeName && $qName === $nodeState->idAttributeName) {
            return true;
        }
        // libxml XML_ATTRIBUTE_ID stamped on the attr (survives importNode; #20830, #23514).
        if (isset($nodeState->attributeIsId[$qName])) {
            return true;
        }
        if (!$docState->isHtmlDocument || self::documentValidateOnParse($document)) {
            $dtdId = $docState->idAttrByElement[$nodeState->nodeName] ?? null;
            if (null !== $dtdId && $dtdId === $qName) {
                return true;
            }
        }
        if (!$docState->isHtmlDocument) {
            if ('xml:id' === $qName) {
                return true;
            }
            if ('id' === $qName
                && self::XML_NAMESPACE_URI === ($nodeState->attributeNamespaces[$qName] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stamp libxml-style ID atype for HTML id / xml:id so importNode preserves getElementById (#20830).
     */
    private static function stampCopyableIdAttributeType(
        ObjectEntry $document,
        DomNodeState $docState,
        DomNodeState $nodeState,
        string $qName
    ): void {
        if ($docState->isHtmlDocument && 'id' === $qName) {
            $nodeState->attributeIsId['id'] = true;

            return;
        }
        if ('xml:id' === $qName) {
            $nodeState->attributeIsId['xml:id'] = true;

            return;
        }
        if ('id' === $qName
            && self::XML_NAMESPACE_URI === ($nodeState->attributeNamespaces['id'] ?? '')
        ) {
            $nodeState->attributeIsId['id'] = true;
        }
    }

    /**
     * Attrs whose libxml atype is XML_ATTRIBUTE_ID — copied by xmlCopyProp / importNode.
     * Includes DTD ATTLIST ID, HTML id, xml:id, and prior attributeIsId stamps.
     * Does not include setIdAttribute-only markers (idAttributeName alone) (#21102, #20830).
     */
    private static function attributeQNameHasCopyableLibxmlIdType(
        ObjectEntry $document,
        DomNodeState $docState,
        DomNodeState $nodeState,
        string $qName
    ): bool {
        if (isset($nodeState->attributeIsId[$qName])) {
            return true;
        }
        if ($docState->isHtmlDocument && 'id' === $qName) {
            return true;
        }
        if (!$docState->isHtmlDocument || self::documentValidateOnParse($document)) {
            $dtdId = $docState->idAttrByElement[$nodeState->nodeName] ?? null;
            if (null !== $dtdId && $dtdId === $qName) {
                return true;
            }
        }
        if (!$docState->isHtmlDocument) {
            if ('xml:id' === $qName) {
                return true;
            }
            if ('id' === $qName
                && self::XML_NAMESPACE_URI === ($nodeState->attributeNamespaces[$qName] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stamp copyable libxml ID types from $sourceDocument onto $destState for importNode (#21102).
     */
    private static function stampCopyableLibxmlIdTypesFromDocument(
        ObjectEntry $sourceDocument,
        DomNodeState $sourceState,
        DomNodeState $destState
    ): void {
        $docState = DomRegistry::state($sourceDocument);
        foreach ($sourceState->attributes as $qName => $_) {
            if (self::attributeQNameHasCopyableLibxmlIdType(
                $sourceDocument,
                $docState,
                $sourceState,
                $qName
            )) {
                $destState->attributeIsId[$qName] = true;
            }
        }
    }

    private static function elementAttributeIsIdBearing(ObjectEntry $element, string $qName): bool
    {
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            return false;
        }

        return self::attributeQNameIsIdBearing(
            $document,
            DomRegistry::state($document),
            DomRegistry::state($element),
            $qName
        );
    }

    /**
     * DOMAttr::isId() — true when attrp->atype == XML_ATTRIBUTE_ID (php-src ext/dom/attr.c; #20129).
     *
     * Orphan attributes (no ownerElement) are never IDs. Attached attrs follow
     * {@see elementAttributeIsIdBearing()} (setIdAttribute* / HTML id / xml:id / DTD).
     */
    public static function attrIsId(ObjectEntry $attr): bool
    {
        if (!self::isAttr($attr)) {
            throw new \TypeError('DOMAttr::isId() must be called on a DOMAttr');
        }
        // Thin-AOT createAttribute / property Attrs may lack DomRegistry (#29884).
        // Orphan / unregistered attrs are never IDs (php-src ext/dom/attr.c).
        if (!DomRegistry::has($attr)) {
            return false;
        }
        $attrState = DomRegistry::state($attr);
        $ownerElementId = $attrState->ownerElementId;
        if (null === $ownerElementId) {
            return false;
        }
        $owner = DomRegistry::entry($ownerElementId);
        if (null === $owner || !self::isElement($owner)) {
            return false;
        }

        return self::elementAttributeIsIdBearing($owner, $attrState->nodeName);
    }

    /**
     * Rebind document elementIds after setAttribute / removeAttribute (#19870).
     * Caller must evaluate {@see attributeQNameIsIdBearing()} before mutating attributes
     * (remove paths clear namespaces that the check needs).
     * setAttributeNode only drops the old id (php-src / libxml); setAttribute re-registers.
     */
    private static function rebindElementIdOnAttributeWrite(
        ObjectEntry $element,
        string $qName,
        ?string $previousValue,
        bool $registerNext,
        bool $isIdBearing
    ): void {
        if (!$isIdBearing) {
            return;
        }
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            return;
        }
        $nodeState = DomRegistry::state($element);
        $docState = DomRegistry::state($document);
        self::unregisterElementId($document, $element, $previousValue);
        if ($registerNext) {
            // Stamp copyable atype for HTML id / xml:id (not setIdAttribute; #20830).
            self::stampCopyableIdAttributeType($document, $docState, $nodeState, $qName);
            self::registerElementId($document, $element);
        } elseif (null !== $nodeState->idAttributeName && $qName === $nodeState->idAttributeName) {
            $nodeState->idAttributeName = null;
        } else {
            unset($nodeState->attributeIsId[$qName]);
        }
        self::syncElementIdMapProperty($document);
    }

    public static function lookupPrefix(ObjectEntry $node, ?string $namespace): ?string
    {
        if (null === $namespace || '' === $namespace) {
            return null;
        }
        $current = $node;
        while (DomRegistry::has($current)) {
            $state = DomRegistry::state($current);
            if (self::isElement($current) && null !== $state->namespaceUri && $state->namespaceUri === $namespace) {
                $ownPrefix = $state->prefix ?? '';

                return '' === $ownPrefix ? null : $ownPrefix;
            }
            foreach ($state->namespaceDeclarations as $prefix => $uri) {
                if ($uri === $namespace) {
                    return '' === $prefix ? null : $prefix;
                }
            }
            $parentId = $state->parentId;
            if (null === $parentId) {
                break;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    public static function lookupNamespaceURI(ObjectEntry $node, ?string $prefix): ?string
    {
        $wantPrefix = $prefix ?? '';
        // libxml / php-src: xml and xmlns are always in scope (ext/dom/node.c; #20615).
        if ('xml' === $wantPrefix) {
            return self::XML_NAMESPACE_URI;
        }
        if ('xmlns' === $wantPrefix) {
            return self::XMLNS_NAMESPACE_URI;
        }
        $current = $node;
        while (DomRegistry::has($current)) {
            $state = DomRegistry::state($current);
            if (self::isElement($current) && null !== $state->namespaceUri) {
                $ownPrefix = $state->prefix ?? '';
                if ($ownPrefix === $wantPrefix) {
                    return $state->namespaceUri;
                }
            }
            if (\array_key_exists($wantPrefix, $state->namespaceDeclarations)) {
                return $state->namespaceDeclarations[$wantPrefix];
            }
            $parentId = $state->parentId;
            if (null === $parentId) {
                break;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * DOMNode::$baseURI — libxml xmlNodeGetBase / php-src dom_node_base_uri_read (#14453, #20199).
     *
     * Resolves HTML {@code <base href>} (HTML docs) and in-scope {@code xml:base} against
     * {@see DomNodeState::$documentUri}.
     */
    public static function readBaseUri(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            // Living Dom\* documents: unset URL → about:blank (php-src follow_spec; #21056).
            $uri = $state->documentUri ?? '';
            if ('' === $uri && VmDomLiving::isLivingDocument($node)) {
                return 'about:blank';
            }

            return $uri;
        }

        $doc = self::ownerDocumentEntry($node);
        if (null === $doc) {
            return '';
        }
        $docState = DomRegistry::state($doc);
        $base = $docState->documentUri ?? '';
        // Living Dom\* nodes inherit document URI; about:blank when URL unset (#21056).
        if ('' === $base && VmDomLiving::isLivingDocument($doc)) {
            $base = 'about:blank';
        }
        if ($docState->isHtmlDocument) {
            $htmlBase = self::findHtmlBaseHref($doc);
            if (null !== $htmlBase && '' !== $htmlBase) {
                $base = self::resolveUri($base, $htmlBase);
            }
        }

        $target = $node;
        if (DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType) {
            if (null === $state->ownerElementId) {
                return $base;
            }
            $owner = DomRegistry::entry($state->ownerElementId);
            if (null === $owner) {
                return $base;
            }
            $target = $owner;
        } elseif (DomConstants::XML_ELEMENT_NODE !== $state->nodeType) {
            $parentId = $state->parentId;
            $target = null;
            while (null !== $parentId) {
                $parent = DomRegistry::entry($parentId);
                if (null === $parent) {
                    break;
                }
                $ps = DomRegistry::state($parent);
                if (DomConstants::XML_ELEMENT_NODE === $ps->nodeType) {
                    $target = $parent;
                    break;
                }
                if (DomConstants::XML_DOCUMENT_NODE === $ps->nodeType) {
                    return $base;
                }
                $parentId = $ps->parentId;
            }
            if (null === $target) {
                return $base;
            }
        }

        /** @var list<ObjectEntry> $chain */
        $chain = [];
        $current = $target;
        while (null !== $current) {
            $cs = DomRegistry::state($current);
            if (DomConstants::XML_DOCUMENT_NODE === $cs->nodeType) {
                break;
            }
            if (DomConstants::XML_ELEMENT_NODE === $cs->nodeType) {
                array_unshift($chain, $current);
            }
            if (null === $cs->parentId) {
                break;
            }
            $current = DomRegistry::entry($cs->parentId);
        }

        foreach ($chain as $el) {
            $xmlBase = self::readXmlBaseAttribute($el);
            if (null !== $xmlBase && '' !== $xmlBase) {
                $base = self::resolveUri($base, $xmlBase);
            }
        }

        return $base;
    }

    /** First HTML {@code <base href>} in document order (libxml HTML base). */
    private static function findHtmlBaseHref(ObjectEntry $document): ?string
    {
        foreach (self::collectElementsByTagName($document, 'base') as $baseId) {
            $baseEl = DomRegistry::entry($baseId);
            if (null === $baseEl) {
                continue;
            }
            $href = self::getAttribute($baseEl, 'href') ?? '';
            if ('' !== $href) {
                return $href;
            }
        }

        return null;
    }

    /** {@code xml:base} via qName or XML namespace (http://www.w3.org/XML/1998/namespace). */
    private static function readXmlBaseAttribute(ObjectEntry $element): ?string
    {
        if (!self::isElement($element)) {
            return null;
        }
        $qName = self::getAttribute($element, 'xml:base') ?? '';
        if ('' !== $qName) {
            return $qName;
        }
        $ns = self::getAttributeNS($element, DomConstants::XML_NS_URI, 'base') ?? '';
        if ('' !== $ns) {
            return $ns;
        }

        return null;
    }

    /** RFC 3986 relative-ref resolution against a base URI/path (libxml xmlBuildURI subset). */
    private static function resolveUri(string $base, string $ref): string
    {
        if ('' === $ref) {
            return $base;
        }
        if (1 === preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        if (!\is_array($b)) {
            $b = [];
        }
        if (str_starts_with($ref, '//')) {
            $scheme = $b['scheme'] ?? null;

            return (null !== $scheme && '' !== $scheme ? $scheme.':' : '').$ref;
        }
        if (isset($ref[0]) && '/' === $ref[0]) {
            return self::buildUri(
                $b['scheme'] ?? null,
                $b['host'] ?? null,
                isset($b['port']) ? (int) $b['port'] : null,
                $ref
            );
        }

        $basePath = $b['path'] ?? '';
        if ('' === $basePath) {
            $basePath = '/';
        }
        if (!str_ends_with($basePath, '/')) {
            $slash = strrpos($basePath, '/');
            $basePath = false === $slash ? '/' : substr($basePath, 0, $slash + 1);
        }
        $merged = self::removeDotSegments($basePath.$ref);

        return self::buildUri(
            $b['scheme'] ?? null,
            $b['host'] ?? null,
            isset($b['port']) ? (int) $b['port'] : null,
            $merged
        );
    }

    private static function buildUri(?string $scheme, ?string $host, ?int $port, string $path): string
    {
        if (null === $scheme || '' === $scheme) {
            if (null === $host || '' === $host) {
                return $path;
            }

            return '//'.$host.(null !== $port ? ':'.$port : '').$path;
        }
        if (null === $host || '' === $host) {
            return $scheme.':'.$path;
        }

        return $scheme.'://'.$host.(null !== $port ? ':'.$port : '').$path;
    }

    /** RFC 3986 §5.2.4 remove_dot_segments. */
    private static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';
        while ('' !== $input) {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = '/'.substr($input, 3);
            } elseif ('/.' === $input) {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = '/'.substr($input, 4);
                $output = preg_replace('#/[^/]*$#', '', $output) ?? '';
            } elseif ('/..' === $input) {
                $input = '/';
                $output = preg_replace('#/[^/]*$#', '', $output) ?? '';
            } elseif ('.' === $input || '..' === $input) {
                $input = '';
            } else {
                if (1 === preg_match('#^(/?[^/]*)#', $input, $m)) {
                    $segment = $m[1];
                    $input = substr($input, \strlen($segment));
                    $output .= $segment;
                } else {
                    $output .= $input;
                    $input = '';
                }
            }
        }

        return $output;
    }

    public static function readNamespaceUri(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType
            || DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType
            || DomConstants::XML_NAMESPACE_DECL_NODE === $state->nodeType
        ) {
            return $state->namespaceUri;
        }

        return null;
    }

    public static function readLocalName(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }
        $state = DomRegistry::state($node);

        return $state->localName ?? $state->nodeName;
    }

    /**
     * Node.nodeName / Element.tagName (php-src ext/dom/node.c + element.c; #21558).
     *
     * HTML namespace elements owned by Dom\HTMLDocument expose ASCII-uppercase names;
     * localName stays lowercase. Legacy DOM* and Dom\XMLDocument keep stored casing.
     */
    public static function readNodeName(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }
        $state = DomRegistry::state($node);
        $name = $state->nodeName;
        if (DomConstants::XML_ELEMENT_NODE !== $state->nodeType
            || VmDomLiving::HTML_NS !== $state->namespaceUri
        ) {
            return $name;
        }
        $owner = self::ownerDocumentEntry($node);
        if (null === $owner
            || VmDomLiving::CLASS_HTML_DOCUMENT !== strtolower($owner->class->name)
        ) {
            return $name;
        }

        // WHATWG: uppercase ASCII form of local name (qualified name equals local for HTML).
        return strtoupper($state->localName ?? $name);
    }

    public static function readPrefix(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }

        return DomRegistry::state($node)->prefix ?? '';
    }

    public static function getLineNo(ObjectEntry $node): int
    {
        if (!DomRegistry::has($node)) {
            return 0;
        }

        return DomRegistry::state($node)->lineNo;
    }

    public static function getNodePath(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_FRAG_NODE === $state->nodeType) {
            return null;
        }
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            return '/';
        }

        $segments = [];
        $current = $node;
        while (null !== $current) {
            $currentState = DomRegistry::state($current);
            if (DomConstants::XML_DOCUMENT_NODE === $currentState->nodeType) {
                break;
            }
            $segment = self::nodePathSegment($current);
            if (null !== $segment && '' !== $segment) {
                array_unshift($segments, $segment);
            }
            if (null === $currentState->parentId) {
                break;
            }
            $current = DomRegistry::entry($currentState->parentId);
            if (null === $current) {
                break;
            }
        }

        if ([] === $segments) {
            return '/';
        }

        return '/'.implode('/', $segments);
    }

    private static function nodePathSegment(ObjectEntry $node): ?string
    {
        $state = DomRegistry::state($node);
        if (DomConstants::XML_TEXT_NODE === $state->nodeType) {
            return 'text()';
        }
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType) {
            $name = $state->nodeName;
            $index = self::elementPathIndexAmongSiblings($node);
            if (null !== $index) {
                return $name.'['.$index.']';
            }

            return $name;
        }

        return $state->nodeName;
    }

    /** 1-based index when multiple element siblings share nodeName (php-src dom_node_get_node_path; #15125). */
    private static function elementPathIndexAmongSiblings(ObjectEntry $node): ?int
    {
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            return null;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            return null;
        }
        $parentState = DomRegistry::state($parent);
        $nodeName = $state->nodeName;
        $sameNameCount = 0;
        $index = 0;
        foreach ($parentState->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            $childState = DomRegistry::state($child);
            if (DomConstants::XML_ELEMENT_NODE !== $childState->nodeType) {
                continue;
            }
            if ($childState->nodeName !== $nodeName) {
                continue;
            }
            ++$sameNameCount;
            if ($child->id === $node->id) {
                $index = $sameNameCount;
            }
        }
        if ($sameNameCount <= 1) {
            return null;
        }

        return $index;
    }

    public static function getRootNode(ObjectEntry $node): ObjectEntry
    {
        if (!DomRegistry::has($node)) {
            return $node;
        }
        $current = $node;
        while (true) {
            $state = DomRegistry::state($current);
            if (null === $state->parentId) {
                return $current;
            }
            $parent = DomRegistry::entry($state->parentId);
            if (null === $parent) {
                return $current;
            }
            $current = $parent;
        }
    }

    /**
     * @return array{0: string, 1: string} prefix, localName
     */
    private static function splitQualifiedName(string $qualifiedName): array
    {
        $pos = strpos($qualifiedName, ':');
        if (false === $pos) {
            return ['', $qualifiedName];
        }

        return [substr($qualifiedName, 0, $pos), substr($qualifiedName, $pos + 1)];
    }

    private static function isXmlnsAttributeName(string $name): bool
    {
        return 'xmlns' === $name || str_starts_with($name, 'xmlns:');
    }

    /**
     * @param array<string, string> $attributes
     *
     * @return array<string, string>
     */
    private static function extractNamespaceDeclarations(array $attributes): array
    {
        $declarations = [];
        foreach ($attributes as $name => $value) {
            if ('xmlns' === $name) {
                $declarations[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $declarations[substr($name, 6)] = $value;
            }
        }

        return $declarations;
    }

    /**
     * Drop xmlns / xmlns:* from the Attr map — they live in nsDef (php-src nodemap.c; #19718).
     *
     * @param array<string, string> $attributes
     *
     * @return array<string, string>
     */
    private static function stripNamespaceDeclarationAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $name => $value) {
            if (!self::isXmlnsAttributeName($name)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Apply parsed attribute string: nsDef + Attr map without xmlns* (#19718).
     * Prefixed Attrs get attributeNamespaces when the URI is known locally or via the
     * built-in xml prefix — parent-declared prefixes stay unset until materialize (#20615).
     *
     * @param array<string, string> $attributes
     */
    private static function applyParsedAttributes(DomNodeState $state, array $attributes): void
    {
        $state->namespaceDeclarations = self::extractNamespaceDeclarations($attributes);
        // Attribute-sourced xmlns only — createElementNS nsDef stays unmarked (#20924).
        $state->xmlnsAttributePrefixes = [];
        foreach (array_keys($state->namespaceDeclarations) as $prefix) {
            $state->xmlnsAttributePrefixes[$prefix] = true;
        }
        $state->attributes = self::stripNamespaceDeclarationAttributes($attributes);
        foreach ($state->attributes as $qName => $_) {
            [$prefix] = self::splitQualifiedName($qName);
            if ('' === $prefix) {
                continue;
            }
            if ('xml' === $prefix) {
                $state->attributeNamespaces[$qName] = self::XML_NAMESPACE_URI;
            } elseif (\array_key_exists($prefix, $state->namespaceDeclarations)) {
                $state->attributeNamespaces[$qName] = $state->namespaceDeclarations[$prefix];
            }
        }
    }

    /** nsDef value for xmlns / xmlns:prefix, or null when absent (#19718). */
    private static function namespaceDeclarationValue(ObjectEntry $element, string $xmlnsName): ?string
    {
        $state = DomRegistry::state($element);
        if ('xmlns' === $xmlnsName) {
            return \array_key_exists('', $state->namespaceDeclarations)
                ? $state->namespaceDeclarations['']
                : null;
        }
        if (str_starts_with($xmlnsName, 'xmlns:')) {
            $prefix = substr($xmlnsName, 6);

            return \array_key_exists($prefix, $state->namespaceDeclarations)
                ? $state->namespaceDeclarations[$prefix]
                : null;
        }

        return null;
    }

    /**
     * DOMElement::getAttribute() — qName lookup; xmlns* reads nsDef (php-src element.c; #19718).
     * Living Dom\* missing → null; legacy DOMElement → '' (php_dom_follow_spec_intern; #26062).
     */
    public static function getAttribute(ObjectEntry $element, string $name): ?string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (self::isXmlnsAttributeName($name)) {
            $nsVal = self::namespaceDeclarationValue($element, $name);
            if (null !== $nsVal) {
                return $nsVal;
            }

            return VmDomLiving::isLivingElement($element) ? null : '';
        }
        $state = DomRegistry::state($element);
        if (\array_key_exists($name, $state->attributes)) {
            return $state->attributes[$name];
        }

        return VmDomLiving::isLivingElement($element) ? null : '';
    }

    /**
     * DOMElement::hasAttribute() — qName presence; xmlns* probes nsDef (php-src element.c; #19718).
     */
    public static function hasAttribute(ObjectEntry $element, string $name): bool
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (self::isXmlnsAttributeName($name)) {
            return null !== self::namespaceDeclarationValue($element, $name);
        }
        $state = DomRegistry::state($element);

        return \array_key_exists($name, $state->attributes);
    }

    /** True when setAttributeNS targets a real xmlns declaration (not a bogus Attr). */
    private static function isXmlnsNamespaceUri(?string $namespace): bool
    {
        return null !== $namespace && self::XMLNS_NAMESPACE_URI === $namespace;
    }

    /**
     * libxml nsDef for prefixed namespaced attributes (php-src ext/dom/element.c; #19458).
     */
    private static function ensureNamespaceDeclarationForPrefixedAttribute(
        ObjectEntry $element,
        ?string $prefix,
        ?string $namespaceUri
    ): void {
        if (null === $prefix || '' === $prefix || null === $namespaceUri || '' === $namespaceUri) {
            return;
        }
        if (self::parentNamespaceUri($element, $prefix) === $namespaceUri) {
            return;
        }
        $state = DomRegistry::state($element);
        $state->namespaceDeclarations[$prefix] = $namespaceUri;
    }

    /**
     * After importNode copy: place ancestor-scoped xmlns on the imported subtree root
     * (php-src/libxml xmlDOMWrapCloneNode; #21482).
     *
     * Bindings already declared on a node inside the subtree stay put; only bindings that
     * were in scope from outside the cloned fragment are materialized on the root.
     */
    private static function reconcileImportedSubtreeNamespaceDeclarations(ObjectEntry $imported): void
    {
        if (self::isDocumentFragment($imported)) {
            $state = DomRegistry::state($imported);
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child && self::isElement($child)) {
                    self::reconcileImportedSubtreeNamespaceDeclarations($child);
                }
            }

            return;
        }
        if (!self::isElement($imported)) {
            return;
        }
        self::walkReconcileImportedNamespaceUses($imported, $imported);
    }

    private static function walkReconcileImportedNamespaceUses(ObjectEntry $node, ObjectEntry $subtreeRoot): void
    {
        $state = DomRegistry::state($node);
        if (self::isElement($node)) {
            $uri = $state->namespaceUri;
            if (null !== $uri && '' !== $uri) {
                $prefix = $state->prefix ?? '';
                self::ensureImportedNamespaceDeclaration($node, $subtreeRoot, $prefix, $uri);
            }
            foreach ($state->attributeNamespaces as $qName => $attrUri) {
                if (null === $attrUri || '' === $attrUri) {
                    continue;
                }
                if (!str_contains($qName, ':')) {
                    continue;
                }
                $attrPrefix = explode(':', $qName, 2)[0];
                if ('xmlns' === $attrPrefix || 'xml' === $attrPrefix) {
                    continue;
                }
                self::ensureImportedNamespaceDeclaration($node, $subtreeRoot, $attrPrefix, $attrUri);
            }
        }
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::walkReconcileImportedNamespaceUses($child, $subtreeRoot);
            }
        }
    }

    /**
     * If $prefix=>$uri is not declared on the path from $useSite up to $subtreeRoot (inclusive),
     * add the nsDef on $subtreeRoot (libxml import placement; #21482).
     */
    private static function ensureImportedNamespaceDeclaration(
        ObjectEntry $useSite,
        ObjectEntry $subtreeRoot,
        string $prefix,
        string $uri
    ): void {
        if ('xml' === $prefix || 'xmlns' === $prefix) {
            return;
        }
        if (self::XML_NAMESPACE_URI === $uri || self::XMLNS_NAMESPACE_URI === $uri) {
            return;
        }
        if (self::namespaceDeclaredOnPathToSubtreeRoot($useSite, $subtreeRoot, $prefix, $uri)) {
            return;
        }
        if (!self::isElement($subtreeRoot)) {
            return;
        }
        DomRegistry::state($subtreeRoot)->namespaceDeclarations[$prefix] = $uri;
    }

    private static function namespaceDeclaredOnPathToSubtreeRoot(
        ObjectEntry $useSite,
        ObjectEntry $subtreeRoot,
        string $prefix,
        string $uri
    ): bool {
        $cur = $useSite;
        while (true) {
            if (self::isElement($cur)) {
                $decls = DomRegistry::state($cur)->namespaceDeclarations;
                if (\array_key_exists($prefix, $decls) && $decls[$prefix] === $uri) {
                    return true;
                }
            }
            if ($cur->id === $subtreeRoot->id) {
                return false;
            }
            $parentId = DomRegistry::state($cur)->parentId;
            if (null === $parentId) {
                return false;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                return false;
            }
            $cur = $parent;
        }
    }

    /**
     * In-scope namespace URI from ancestors only (php-src/libxml ns dump; #19397).
     */
    private static function parentNamespaceUri(ObjectEntry $entry, string $prefix): ?string
    {
        $state = DomRegistry::state($entry);
        if (null === $state->parentId) {
            return null;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent || self::isDocument($parent)) {
            return null;
        }

        return self::lookupNamespaceURI($parent, '' === $prefix ? null : $prefix);
    }

    private static function resolveElementNamespaceUri(ObjectEntry $element): void
    {
        if (!self::isElement($element)) {
            return;
        }
        $state = DomRegistry::state($element);
        $prefix = $state->prefix ?? '';
        if ('' === $prefix) {
            $state->namespaceUri = self::lookupNamespaceURI($element, '');
        } else {
            $state->namespaceUri = self::lookupNamespaceURI($element, $prefix);
        }
    }

    /** Depth-first namespaceURI refresh once ancestors are linked (php-src/libxml; #19467). */
    private static function resolveSubtreeNamespaceUris(ObjectEntry $entry): void
    {
        if (!DomRegistry::has($entry)) {
            return;
        }
        if (self::isElement($entry)) {
            self::resolveElementNamespaceUri($entry);
        }
        $state = DomRegistry::state($entry);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::resolveSubtreeNamespaceUris($child);
            }
        }
    }

    public static function createTextNode(Context $ctx, string $data, ?ObjectEntry $ownerDocument = null): ObjectEntry
    {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_TEXT);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string('#text');
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_TEXT_NODE;
        $state->nodeName = '#text';
        $state->textContent = $data;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        return $entry;
    }

    public static function createComment(Context $ctx, string $data, ?ObjectEntry $ownerDocument = null): ObjectEntry
    {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_COMMENT);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string('#comment');
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_COMMENT_NODE;
        $state->nodeName = '#comment';
        $state->textContent = $data;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        return $entry;
    }

    public static function createCdataSection(Context $ctx, string $data, ?ObjectEntry $ownerDocument = null): ObjectEntry
    {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_CDATA);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string('#cdata-section');
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_CDATA_SECTION_NODE;
        $state->nodeName = '#cdata-section';
        $state->textContent = $data;
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        return $entry;
    }

    public static function characterDataReadContent(ObjectEntry $node): string
    {
        if (!self::isCharacterData($node)) {
            throw new \LogicException('characterDataReadContent() called on non-character-data node');
        }

        return DomRegistry::state($node)->textContent ?? '';
    }

    public static function writeCharacterDataContent(ObjectEntry $node, string $content): void
    {
        if (!self::isCharacterData($node)) {
            throw new \LogicException('writeCharacterDataContent() called on non-character-data node');
        }
        DomRegistry::state($node)->textContent = $content;
    }

    public static function characterDataAppendData(ObjectEntry $node, string $arg): void
    {
        self::writeCharacterDataContent($node, self::characterDataReadContent($node).$arg);
    }

    public static function characterDataInsertData(ObjectEntry $node, int $offset, string $arg): void
    {
        $data = self::characterDataReadContent($node);
        $len = \strlen($data);
        if ($offset < 0 || $offset > $len) {
            throw new \DOMException('Index Size Error', DomExceptionConstants::INDEX_SIZE_ERR);
        }
        self::writeCharacterDataContent($node, substr($data, 0, $offset).$arg.substr($data, $offset));
    }

    public static function characterDataDeleteData(ObjectEntry $node, int $offset, int $count): void
    {
        $data = self::characterDataReadContent($node);
        $len = \strlen($data);
        if ($offset < 0 || $offset > $len || $count < 0) {
            throw new \DOMException('Index Size Error', DomExceptionConstants::INDEX_SIZE_ERR);
        }
        if ($count > $len - $offset) {
            $count = $len - $offset;
        }
        self::writeCharacterDataContent($node, substr($data, 0, $offset).substr($data, $offset + $count));
    }

    public static function characterDataReplaceData(ObjectEntry $node, int $offset, int $count, string $arg): void
    {
        $data = self::characterDataReadContent($node);
        $len = \strlen($data);
        if ($offset < 0 || $offset > $len || $count < 0) {
            throw new \DOMException('Index Size Error', DomExceptionConstants::INDEX_SIZE_ERR);
        }
        if ($count > $len - $offset) {
            $count = $len - $offset;
        }
        self::writeCharacterDataContent(
            $node,
            substr($data, 0, $offset).$arg.substr($data, $offset + $count)
        );
    }

    public static function characterDataSubstringData(ObjectEntry $node, int $offset, int $count): string
    {
        if (!self::isCharacterData($node)) {
            throw new \LogicException('characterDataSubstringData() called on non-character-data node');
        }
        $data = self::characterDataReadContent($node);
        $len = \strlen($data);
        if ($offset < 0 || $count < 0 || $offset > $len) {
            throw new \DOMException('Index Size Error', DomExceptionConstants::INDEX_SIZE_ERR);
        }

        return substr($data, $offset, $count);
    }

    /** Adjacent text/CDATA merge (php-src ext/dom/text.c dom_text_whole_text_read; #17527). */
    public static function readWholeText(ObjectEntry $node): string
    {
        if (!self::isTextOrCdataNode($node)) {
            throw new \LogicException('readWholeText() called on non-text node');
        }
        $start = $node;
        while (true) {
            $prev = self::siblingEntry($start, self::PROP_PREVIOUS_SIBLING);
            if (null === $prev || !self::isTextOrCdataNode($prev)) {
                break;
            }
            $start = $prev;
        }
        $merged = '';
        $current = $start;
        while (null !== $current && self::isTextOrCdataNode($current)) {
            $merged .= self::characterDataReadContent($current);
            $current = self::siblingEntry($current, self::PROP_NEXT_SIBLING);
        }

        return $merged;
    }

    /** Blank text node probe (php-src ext/dom/text.c dom_text_is_whitespace_in_element_content). */
    public static function textIsWhitespaceInElementContent(ObjectEntry $node): bool
    {
        if (!self::isTextOrCdataNode($node)) {
            throw new \LogicException('textIsWhitespaceInElementContent() called on non-text node');
        }
        $data = self::characterDataReadContent($node);
        if ('' === $data) {
            return true;
        }
        $len = \strlen($data);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $data[$i];
            if (' ' !== $ch && "\t" !== $ch && "\n" !== $ch && "\r" !== $ch) {
                return false;
            }
        }

        return true;
    }

    public static function textSplitText(Context $ctx, ObjectEntry $node, int $offset): ?ObjectEntry
    {
        if (!self::isTextOrCdataNode($node)) {
            throw new \LogicException('textSplitText() called on non-text node');
        }
        if ($offset < 0) {
            throw new \ValueError('DOMText::splitText(): Argument #1 ($offset) must be greater than or equal to 0');
        }
        $data = self::characterDataReadContent($node);
        $len = \strlen($data);
        if ($offset > $len) {
            return null;
        }
        $ownerDocument = self::ownerDocumentEntry($node);
        $tailNode = self::createTextNode($ctx, substr($data, $offset), $ownerDocument);
        self::writeCharacterDataContent($node, substr($data, 0, $offset));
        $state = DomRegistry::state($node);
        if (null !== $state->parentId) {
            $parent = DomRegistry::entry($state->parentId);
            if (null !== $parent) {
                self::insertAfterSibling($ctx, $parent, $tailNode, $node);
                self::syncSubtree($ctx, $parent);
            }
        }

        return $tailNode;
    }

    public static function createEntityReference(
        Context $ctx,
        string $name,
        ?ObjectEntry $ownerDocument = null
    ): Variable {
        self::assertValidEntityReferenceName($name);

        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ENTITY_REFERENCE);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ENTITY_REF_NODE;
        $state->nodeName = $name;
        if (null !== $ownerDocument) {
            self::ensureDocument($ownerDocument);
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createDocumentFragment(Context $ctx, ?ObjectEntry $ownerDocument = null): Variable
    {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_DOCUMENT_FRAGMENT);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string('#document-fragment');
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_FRAG_NODE;
        $state->nodeName = '#document-fragment';
        // php-src ext/dom/document.c dom_document_create_document_fragment — owner is creating document (#20203).
        if (null !== $ownerDocument && self::isDocument($ownerDocument)) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);
        self::ensureChildNodesList($ctx, $entry);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function appendXML(
        Context $ctx,
        ObjectEntry $fragment,
        string $data,
        ?\PHPCompiler\Frame $frame = null
    ): bool {
        self::ensureDocumentFragment($fragment);
        if ('' === $data) {
            return false;
        }
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return true;
        }
        $children = self::parseFragmentXmlChildren($ctx, $trimmed);
        if (null === $children) {
            self::reportDomFragmentAppendXmlError($ctx, $trimmed, $frame);

            return false;
        }
        foreach ($children as $child) {
            self::appendChild($ctx, $fragment, $child);
        }

        return true;
    }

    public static function loadXML(
        Context $ctx,
        ObjectEntry $document,
        string $xml,
        ?\PHPCompiler\Frame $frame = null,
        int $options = 0
    ): bool {
        self::ensureDocument($document);
        self::rejectEmptyLoadSource($xml, 'DOMDocument::loadXML()');

        // libxml xmlReadMemory skips a leading UTF-8 BOM (php-src ext/dom/document.c; #26565).
        $xml = self::stripLeadingUtf8Bom($xml);

        // ltrim for parse structure; keep leading byte count for getLineNo (#20795).
        // Preserve trailing newlines so premature-end EOF line/column match libxml (#24319).
        $leadingLen = \strlen($xml) - \strlen(ltrim($xml));
        $trimmed = ltrim($xml);
        $decl = self::parseXmlDeclaration($trimmed);
        $idAttrByElement = self::parseDoctypeIdAttributes($trimmed);
        $dtdDefaultAttrs = self::parseDoctypeDefaultAttributes($trimmed);
        $substituteEntities = 0 !== ($options & LibxmlConstants::LIBXML_NOENT);
        $generalEntities = self::parseDoctypeGeneralEntities($ctx, $trimmed, $substituteEntities, $frame);
        $parts = self::splitXmlDocumentParts($trimmed);
        if (null === $parts) {
            // Prefer libxml-shaped diagnostics (e.g. unclosed start tag) over a generic
            // "Malformed XML document" — php-src ext/dom/php_dom.c via libxml2 (#16192, #19505).
            self::reportDomLoadXmlValidationErrors($ctx, $trimmed, $frame);

            return false;
        }
        $elementXml = $parts['rootXml'];
        $elementOffset = $parts['rootOffset'];
        $validationErrors = VmXml::validationErrorRecords($elementXml);
        if ([] !== $validationErrors) {
            foreach ($validationErrors as $validationError) {
                self::reportDomLibxmlError(
                    $ctx,
                    $validationError['message'],
                    $validationError['code'],
                    $validationError['column'],
                    $frame,
                    $validationError['level'],
                    $validationError['line']
                );
            }

            return false;
        }
        // libxml rejects undeclared general entities (XML_ERR_UNDECLARED_ENTITY) — do not
        // silently keep `&foo;` as text that later serializes as `&amp;foo;` (#22774).
        $undeclaredEntity = self::detectUndeclaredEntityInElementXml(
            $elementXml,
            $generalEntities,
            $elementOffset
        );
        if (null !== $undeclaredEntity) {
            self::reportDomLibxmlError(
                $ctx,
                $undeclaredEntity['message'],
                $undeclaredEntity['code'],
                $undeclaredEntity['column'],
                $frame,
                $undeclaredEntity['level']
            );

            return false;
        }
        // Pass $document so living Dom\XMLDocument nodeClassMap → Dom\Element (#20856).
        $root = self::parseElementTree(
            $ctx,
            $elementXml,
            $xml,
            $leadingLen + $elementOffset,
            $generalEntities,
            $substituteEntities,
            $document
        );
        if (null === $root) {
            return false;
        }

        if (self::documentValidateOnParse($document)) {
            self::validateOnParseDtd($ctx, $trimmed, $root, $frame);
        }

        $state = DomRegistry::state($document);
        $childIds = [];
        foreach ($parts['miscBefore'] as $misc) {
            $childIds[] = self::attachDocumentMiscChild($ctx, $document, $misc)->id;
        }
        if (null !== $parts['doctype']) {
            $doctype = self::attachDoctypeChild(
                $ctx,
                $document,
                $parts['doctype']['name'],
                $parts['doctype']['publicId'],
                $parts['doctype']['systemId']
            );
            $childIds[] = $doctype->id;
            self::populateDoctypeInternalSubset($ctx, $doctype, $document, $trimmed);
        }
        foreach ($parts['miscBetween'] as $misc) {
            $childIds[] = self::attachDocumentMiscChild($ctx, $document, $misc)->id;
        }
        $childIds[] = $root->id;
        foreach ($parts['miscAfter'] as $misc) {
            $childIds[] = self::attachDocumentMiscChild($ctx, $document, $misc)->id;
        }
        $state->childIds = $childIds;
        $state->idAttrByElement = $idAttrByElement;
        $state->generalEntities = $generalEntities;
        $state->elementIds = [];
        $state->xmlVersion = $decl['version'];
        $state->encoding = $decl['encoding'];
        $state->xmlStandalone = $decl['standalone'];
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));
        self::linkChildToParent($root, $document);
        // Resolve after the full parent chain exists — nested parse only sees local parents (#19467).
        self::resolveSubtreeNamespaceUris($root);
        self::propagateDocumentId($root, $document->id);
        // php-src / libxml XML_PARSE_NOCDATA — CDATA → text + coalesce adjacent text (#22754).
        if (0 !== ($options & LibxmlConstants::LIBXML_NOCDATA)) {
            self::convertCdataSectionsToTextOnLoad($ctx, $root, $document);
        }
        // php-src: preserveWhiteSpace=false and/or LIBXML_NOBLANKS → XML_PARSE_NOBLANKS (#20476).
        if (self::shouldStripBlankNodesOnLoad($document, $options)) {
            self::stripBlankTextNodesFromSubtree($root);
        }
        // Index explicit ID attrs before synthesizing ATTLIST defaults (#21456).
        // libxml still exposes default/FIXED attrs via getAttribute, but default-only
        // ID values are not registered in the document ID table (Zend getElementById null).
        self::reindexDocumentIds($document, $root);
        if ([] !== $dtdDefaultAttrs) {
            self::applyDtdDefaultAttributesToSubtree($root, $dtdDefaultAttrs);
        }
        self::syncSubtree($ctx, $document);
        self::syncElementIdMapProperty($document);
        // Living Dom\* docs: unset URL → about:blank (php-src follow_spec; #20898).
        $state->documentUri = VmDomLiving::isLivingDocument($document) ? null : self::defaultDocumentUri();
        $state->loadedViaXml = true;
        $state->sourceXml = $trimmed;

        return true;
    }

    public static function load(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        int $options = 0,
        ?\PHPCompiler\Frame $frame = null
    ): bool {
        $contents = VmFsReadNative::read($filename);
        if (false === $contents) {
            VmLibxml::handleError($ctx, [
                'level' => 2,
                'code' => 4,
                'column' => 0,
                'message' => 'failed to load external entity "'.$filename.'"',
                'file' => null !== $frame ? '' : $filename,
                'line' => 0,
            ], $frame, null, 'DOMDocument::load(): I/O warning : failed to load external entity "'.$filename.'"');

            return false;
        }

        if (!self::loadXML($ctx, $document, $contents, $frame, $options)) {
            return false;
        }
        // php-src / libxml: document URL is the loaded filename (needed for relative
        // XInclude href + xml:base fixup; loadXML alone stamps cwd) (#24775, #14468).
        DomRegistry::state($document)->documentUri = self::normalizeLoadedDocumentUri($filename);

        return true;
    }

    /** Absolute path form for {@see DomNodeState::$documentUri} after DOMDocument::load(). */
    private static function normalizeLoadedDocumentUri(string $filename): string
    {
        if ('' === $filename) {
            return self::defaultDocumentUri();
        }
        if (str_starts_with($filename, 'file://')) {
            $filename = substr($filename, 7);
        }
        if (1 === preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $filename)) {
            return $filename;
        }
        if (str_starts_with($filename, '/')) {
            return $filename;
        }
        $base = self::defaultDocumentUri();
        $resolved = self::resolveUri($base, $filename);

        return str_starts_with($resolved, 'file://') ? substr($resolved, 7) : $resolved;
    }

    public static function loadHTMLFile(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        int $options = 0,
        ?\PHPCompiler\Frame $frame = null
    ): bool {
        self::rejectEmptyFilename($filename, 'DOMDocument::loadHTMLFile()');
        $contents = VmFsReadNative::read($filename);
        if (false === $contents) {
            VmLibxml::handleError($ctx, [
                'level' => 2,
                'code' => 4,
                'column' => 0,
                'message' => 'failed to load external entity "'.$filename.'"',
                'file' => null !== $frame ? '' : $filename,
                'line' => 0,
            ], $frame, null, 'DOMDocument::loadHTMLFile(): I/O warning : failed to load external entity "'.$filename.'"');

            return false;
        }

        return self::loadHTML($ctx, $document, $contents, $options, $frame);
    }

    /** php-src ext/dom/document.c — empty $source rejected since PHP 8.0 (#17616). */
    private static function rejectEmptyLoadSource(string $source, string $method): void
    {
        if ('' === $source) {
            throw new \ValueError($method.': Argument #1 ($source) must not be empty');
        }
    }

    /**
     * libxml xmlReadMemory skips a leading UTF-8 BOM before parsing (#26565).
     *
     * Only the absolute prefix is stripped — whitespace before a BOM still fails like Zend.
     */
    public static function stripLeadingUtf8Bom(string $xml): string
    {
        return str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
    }

    /** php-src ext/dom/document.c — empty $filename rejected since PHP 8.0 (#18734). */
    private static function rejectEmptyFilename(string $filename, string $method): void
    {
        if ('' === $filename) {
            throw new \ValueError($method.': Argument #1 ($filename) must not be empty');
        }
    }

    /**
     * php-src ext/dom/element.c — empty attribute QName → ValueError (#24480).
     *
     * @param string $method Class::method without trailing "()"
     */
    public static function rejectEmptyQualifiedName(string $name, string $method, int $argumentNumber): void
    {
        if ('' === $name) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($qualifiedName) cannot be empty',
                $method,
                $argumentNumber
            ));
        }
    }

    /**
     * Zend dom_document_documenturi_read default for in-memory documents (ext/dom/document.c; #14468).
     *
     * Prefer {@see PHP_COMPILER_CLI_INVOCATION_CWD} so relative XInclude/href resolution matches the
     * process cwd before bin/vm.php chdirs to the repo root (#20403, #1770).
     */
    private static function defaultDocumentUri(): string
    {
        $cwd = getenv('PHP_COMPILER_CLI_INVOCATION_CWD');
        if (!\is_string($cwd) || '' === $cwd) {
            $cwd = getcwd();
        }
        if (false === $cwd || '' === $cwd) {
            return '/';
        }

        return str_ends_with($cwd, '/') ? $cwd : $cwd.'/';
    }

    public static function getElementById(ObjectEntry $document, string $elementId): ?ObjectEntry
    {
        self::ensureDocument($document);
        $state = DomRegistry::state($document);
        $objectId = $state->elementIds[$elementId] ?? null;
        if (null === $objectId) {
            return null;
        }
        $entry = DomRegistry::entry($objectId);
        // php-src ext/dom/document.c — refuse IDs whose element is not in the document tree
        // (bug 77686 / php_dom_is_node_connected); #23999.
        if (null === $entry || !self::isConnected($entry)) {
            return null;
        }

        return $entry;
    }

    private static function reindexDocumentIds(ObjectEntry $document, ObjectEntry $root): void
    {
        $docState = DomRegistry::state($document);
        $docState->elementIds = [];
        self::indexElementIdsRecursive($document, $root);
    }

    private static function documentValidateOnParse(ObjectEntry $document): bool
    {
        if (!$document->hasProperty(self::PROP_VALIDATE_ON_PARSE)) {
            return false;
        }
        $prop = $document->getProperty(self::PROP_VALIDATE_ON_PARSE)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $prop->type) {
            return false;
        }
        try {
            return $prop->toBool();
        } catch (\Error) {
            return false;
        }
    }

    /**
     * DOMDocument::$preserveWhiteSpace default true (php-src ext/dom/document.c; #14368 / #20476).
     */
    private static function documentPreserveWhiteSpace(ObjectEntry $document): bool
    {
        return self::ensureDomDocumentBoolProperty($document, self::PROP_PRESERVE_WHITE_SPACE, true);
    }

    /**
     * libxml XML_PARSE_NOBLANKS when preserveWhiteSpace is false or LIBXML_NOBLANKS is set
     * (php-src ext/dom/document.c + ext/libxml/libxml.c; #20476).
     */
    private static function shouldStripBlankNodesOnLoad(ObjectEntry $document, int $options): bool
    {
        if (0 !== ($options & LibxmlConstants::LIBXML_NOBLANKS)) {
            return true;
        }

        return !self::documentPreserveWhiteSpace($document);
    }

    /**
     * LIBXML_NOCDATA / XML_PARSE_NOCDATA — replace CDATA with text and coalesce adjacent text
     * (php-src ext/dom/document.c via libxml2; #22754).
     *
     * Lone empty CDATA becomes an empty #text node; empty CDATA adjacent to text merges away.
     */
    private static function convertCdataSectionsToTextOnLoad(
        Context $ctx,
        ObjectEntry $node,
        ObjectEntry $ownerDocument
    ): void {
        $state = DomRegistry::state($node);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::nodeSupportsChildList($child)) {
                self::convertCdataSectionsToTextOnLoad($ctx, $child, $ownerDocument);
            }
        }
        if (!self::nodeSupportsChildList($node)) {
            return;
        }

        $mergedChildIds = [];
        $carryTextId = null;
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (self::isCdataNode($child)) {
                $cdataState = DomRegistry::state($child);
                $textNode = self::createTextNode($ctx, $cdataState->textContent ?? '', $ownerDocument);
                DomRegistry::state($textNode)->lineNo = $cdataState->lineNo;
                self::linkChildToParent($child, null);
                self::linkChildToParent($textNode, $node);
                $child = $textNode;
                $childId = $textNode->id;
            } elseif (!self::isTextNode($child)) {
                $carryTextId = null;
                $mergedChildIds[] = $childId;

                continue;
            }

            $text = DomRegistry::state($child)->textContent ?? '';
            if ('' === $text) {
                if (null !== $carryTextId) {
                    self::linkChildToParent($child, null);

                    continue;
                }
                $carryTextId = $childId;
                $mergedChildIds[] = $childId;

                continue;
            }
            if (null !== $carryTextId) {
                $carry = DomRegistry::entry($carryTextId);
                if (null !== $carry) {
                    self::setTextNodeData($carry, (DomRegistry::state($carry)->textContent ?? '').$text);
                    self::linkChildToParent($child, null);
                }

                continue;
            }
            $carryTextId = $childId;
            $mergedChildIds[] = $childId;
        }
        $state->childIds = $mergedChildIds;
    }

    /**
     * Drop whitespace-only text/CDATA descendants (libxml xmlIsBlankNode under XML_PARSE_NOBLANKS).
     *
     * Does not touch loadHTML — Zend does not apply this property the same way on the HTML parser.
     */
    private static function stripBlankTextNodesFromSubtree(ObjectEntry $node): void
    {
        $state = DomRegistry::state($node);
        $kept = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (self::isTextOrCdataNode($child) && self::textIsWhitespaceInElementContent($child)) {
                self::linkChildToParent($child, null);
                continue;
            }
            $kept[] = $childId;
            self::stripBlankTextNodesFromSubtree($child);
        }
        $state->childIds = $kept;
    }

    /** php-src ext/dom/node.c — xml:id namespace URI. */
    private const XML_NAMESPACE_URI = 'http://www.w3.org/XML/1998/namespace';

    /** xmlns / xmlns:* declaration namespace (php-src / libxml; #19718). */
    private const XMLNS_NAMESPACE_URI = 'http://www.w3.org/2000/xmlns/';

    private static function indexElementIdsRecursive(ObjectEntry $document, ObjectEntry $node): void
    {
        if (self::isElement($node)) {
            $docState = DomRegistry::state($document);
            $nodeState = DomRegistry::state($node);
            $idAttr = self::resolveElementIdAttributeName($document, $docState, $nodeState);
            if (null !== $idAttr) {
                $value = $nodeState->attributes[$idAttr] ?? null;
                // Document-order first wins — do not overwrite an existing ID slot (#25275).
                if (null !== $value && '' !== $value && !\array_key_exists($value, $docState->elementIds)) {
                    $docState->elementIds[$value] = $node->id;
                }
            }
            foreach ($nodeState->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    self::indexElementIdsRecursive($document, $child);
                }
            }

            return;
        }
        if (!DomRegistry::has($node)) {
            return;
        }
        // Document fragments / non-element containers: walk children only.
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::indexElementIdsRecursive($document, $child);
            }
        }
    }

    /**
     * Drop subtree ID map entries (php-src ext/dom/node.c — remove from ID hash; #19212).
     */
    private static function unregisterElementIdsRecursive(ObjectEntry $document, ObjectEntry $node): void
    {
        if (self::isElement($node)) {
            $docState = DomRegistry::state($document);
            $nodeState = DomRegistry::state($node);
            $idAttr = self::resolveElementIdAttributeName($document, $docState, $nodeState);
            if (null !== $idAttr) {
                $value = $nodeState->attributes[$idAttr] ?? null;
                if (null !== $value && '' !== $value
                    && ($docState->elementIds[$value] ?? null) === $node->id
                ) {
                    unset($docState->elementIds[$value]);
                }
            }
            foreach ($nodeState->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    self::unregisterElementIdsRecursive($document, $child);
                }
            }

            return;
        }
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::unregisterElementIdsRecursive($document, $child);
            }
        }
    }

    /** True when $node has the document as an ancestor (live tree, not orphan/fragment-only). */
    public static function isConnected(ObjectEntry $node): bool
    {
        if (!DomRegistry::has($node)) {
            return false;
        }
        if (self::isDocument($node)) {
            return true;
        }
        $current = $node;
        while (true) {
            $parentId = DomRegistry::state($current)->parentId;
            if (null === $parentId) {
                return false;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                return false;
            }
            if (self::isDocument($parent)) {
                return true;
            }
            $current = $parent;
        }
    }

    /**
     * After insert/append/import into the live tree — index ID attrs (php-src ext/dom/node.c; #19212).
     */
    private static function registerSubtreeElementIdsIfConnected(ObjectEntry $node): void
    {
        if (!self::isConnected($node)) {
            return;
        }
        $document = self::ownerDocumentEntry($node);
        if (null === $document) {
            return;
        }
        self::indexElementIdsRecursive($document, $node);
        self::syncElementIdMapProperty($document);
    }

    /**
     * Drop ID table entries when nodes are about to be destroyed (replaceChildren / textContent),
     * matching libxml xmlFreeNode → xmlRemoveID. Detach via removeChild/replaceChild must NOT
     * clear IDs — libxml keeps them until destruction (#25274, php-src document.c getElementById).
     */
    private static function unregisterSubtreeElementIdsIfConnected(ObjectEntry $node): void
    {
        if (!self::isConnected($node)) {
            return;
        }
        $document = self::ownerDocumentEntry($node);
        if (null === $document) {
            return;
        }
        self::unregisterElementIdsRecursive($document, $node);
        self::syncElementIdMapProperty($document);
    }

    /**
     * Resolve which attribute qName holds this element's document-wide ID (php-src ext/dom/node.c; #19211).
     */
    private static function resolveElementIdAttributeName(
        ObjectEntry $document,
        DomNodeState $docState,
        DomNodeState $nodeState
    ): ?string {
        $idAttr = null;
        if (!$docState->isHtmlDocument || self::documentValidateOnParse($document)) {
            $idAttr = $docState->idAttrByElement[$nodeState->nodeName] ?? null;
        }
        if (null === $idAttr && null !== $nodeState->idAttributeName) {
            $idAttr = $nodeState->idAttributeName;
        }
        // Copied / stamped libxml XML_ATTRIBUTE_ID markers (HTML parse, htmlSetProp, HTML→XML
        // importNode; #20830). Do not auto-promote bare HTML `id` — XML→HTML import keeps
        // isId=false until remove+setAttribute (#23514).
        if (null === $idAttr) {
            foreach ($nodeState->attributeIsId as $qName => $_) {
                if (isset($nodeState->attributes[$qName]) && '' !== $nodeState->attributes[$qName]) {
                    $idAttr = $qName;
                    break;
                }
            }
        }
        if (null === $idAttr && !$docState->isHtmlDocument) {
            if (isset($nodeState->attributes['xml:id'])) {
                $idAttr = 'xml:id';
                $nodeState->attributeIsId['xml:id'] = true;
            } elseif (isset($nodeState->attributes['id'])
                && self::XML_NAMESPACE_URI === ($nodeState->attributeNamespaces['id'] ?? '')) {
                $idAttr = 'id';
                $nodeState->attributeIsId['id'] = true;
            }
        }

        return $idAttr;
    }

    /**
     * @return array{version: string, encoding: ?string, standalone: bool}
     */
    private static function parseXmlDeclaration(string $xml): array
    {
        $version = '1.0';
        $encoding = null;
        $standalone = false;
        if (!preg_match('/^\s*<\?xml\s+([^?]*)\?>/s', $xml, $match)) {
            return [
                'version' => $version,
                'encoding' => $encoding,
                'standalone' => $standalone,
            ];
        }
        $attrs = $match[1];
        if (preg_match('/version\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $versionMatch)) {
            $version = $versionMatch[2];
        }
        if (preg_match('/encoding\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $encodingMatch)) {
            $encoding = $encodingMatch[2];
        }
        if (preg_match('/standalone\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $standaloneMatch)) {
            $standalone = 'yes' === strtolower($standaloneMatch[2]);
        }

        return [
            'version' => $version,
            'encoding' => $encoding,
            'standalone' => $standalone,
        ];
    }

    private static function serializeXmlDeclaration(DomNodeState $state): string
    {
        $decl = '<?xml version="'.self::escapeAttribute($state->xmlVersion).'"';
        if (null !== $state->encoding && '' !== $state->encoding) {
            $decl .= ' encoding="'.self::escapeAttribute($state->encoding).'"';
        }
        if ($state->xmlStandalone) {
            $decl .= ' standalone="yes"';
        }
        $decl .= '?>';

        return $decl;
    }

    private static function escapeAttribute(string $value): string
    {
        return str_replace(['&', '"', '<'], ['&amp;', '&quot;', '&lt;'], $value);
    }

    /**
     * DTD validation warnings when validateOnParse is true (php-src ext/dom/document.c; #14536).
     */
    private static function validateOnParseDtd(
        Context $ctx,
        string $xml,
        ObjectEntry $root,
        ?\PHPCompiler\Frame $frame
    ): void {
        $doctypeName = self::parseDoctypeName($xml);
        if (null === $doctypeName) {
            self::reportDomLibxmlError($ctx, 'Validation failed: no DTD found !', 522, 1, $frame);

            return;
        }

        $rootName = DomRegistry::state($root)->nodeName;
        if ($doctypeName !== $rootName) {
            self::reportDomLibxmlError(
                $ctx,
                "root and DTD name do not match '{$rootName}' and '{$doctypeName}'",
                531,
                self::approximateXmlColumn($xml, $rootName),
                $frame
            );
        }

        $declaredElements = self::parseDoctypeElementDeclarations($xml);
        $elementNames = self::collectElementNames($root);
        sort($elementNames, SORT_STRING);
        foreach ($elementNames as $elementName) {
            if (!isset($declaredElements[$elementName])) {
                self::reportDomLibxmlError(
                    $ctx,
                    "No declaration for element {$elementName}",
                    534,
                    self::approximateXmlColumn($xml, $elementName),
                    $frame
                );
            }
        }
    }

    private static function reportDomLibxmlError(
        Context $ctx,
        string $message,
        int $code,
        int $column,
        ?\PHPCompiler\Frame $frame,
        int $level = LibxmlConstants::LIBXML_ERR_ERROR,
        int $line = 1
    ): void {
        VmLibxml::handleError($ctx, [
            'level' => $level,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => '',
            'line' => $line,
        ], $frame, null, 'DOMDocument::loadXML(): '.rtrim($message).' in Entity, line: '.$line);
    }

    /**
     * Scan document-element markup for undeclared / unterminated entity refs (libxml2;
     * php-src ext/dom/document.c; #22774).
     *
     * Skips CDATA, comments, and PIs. Predefined entities, DTD general entities, and
     * numeric character references are accepted.
     *
     * @param array<string, string> $generalEntities
     *
     * @return null|array{message: string, code: int, column: int, level: int}
     */
    private static function detectUndeclaredEntityInElementXml(
        string $elementXml,
        array $generalEntities,
        int $columnBase = 0
    ): ?array {
        $len = \strlen($elementXml);
        $pos = 0;
        while ($pos < $len) {
            if ('<' === $elementXml[$pos]) {
                $cdata = VmXml::parseCdataSectionAt($elementXml, $pos);
                if (null !== $cdata) {
                    $pos = $cdata['end'];

                    continue;
                }
                $comment = VmXml::parseCommentAt($elementXml, $pos);
                if (null !== $comment) {
                    $pos = $comment['end'];

                    continue;
                }
                $pi = VmXml::parseProcessingInstructionAt($elementXml, $pos);
                if (null !== $pi) {
                    $pos = $pi['end'];

                    continue;
                }
                ++$pos;

                continue;
            }
            if ('&' !== $elementXml[$pos]) {
                ++$pos;

                continue;
            }
            $amp = $pos;
            $afterAmp = $amp + 1;
            if ($afterAmp >= $len) {
                return self::entityRefExpectingSemicolonError($columnBase + $amp + 1);
            }
            // Character references: &#...; / &#x...; (finer libxml diagnostics out of #22774 scope).
            if ('#' === $elementXml[$afterAmp]) {
                $semi = strpos($elementXml, ';', $afterAmp + 1);
                if (false === $semi) {
                    return self::entityRefExpectingSemicolonError($columnBase + $len);
                }
                $pos = $semi + 1;

                continue;
            }
            $semi = strpos($elementXml, ';', $afterAmp);
            if (false === $semi) {
                // libxml column is the first non-Name char where ';' was expected (#22774).
                $nameEnd = $afterAmp;
                while ($nameEnd < $len && 1 === preg_match('/[A-Za-z0-9._:-]/', $elementXml[$nameEnd])) {
                    ++$nameEnd;
                }

                return self::entityRefExpectingSemicolonError($columnBase + $nameEnd + 1);
            }
            // Name must be contiguous through ';'; any other char → expecting ';'.
            $nameEnd = $afterAmp;
            while ($nameEnd < $semi && 1 === preg_match('/[A-Za-z0-9._:-]/', $elementXml[$nameEnd])) {
                ++$nameEnd;
            }
            if ($nameEnd !== $semi) {
                return self::entityRefExpectingSemicolonError($columnBase + $nameEnd + 1);
            }
            $refName = substr($elementXml, $afterAmp, $semi - $afterAmp);
            if ('' === $refName) {
                return self::entityRefExpectingSemicolonError($columnBase + $semi + 1);
            }
            if (isset($generalEntities[$refName])
                || null !== self::decodePredefinedXmlEntity($refName)
            ) {
                $pos = $semi + 1;

                continue;
            }

            // XML_ERR_UNDECLARED_ENTITY — column is 1-based index of the char after ';'.
            return [
                'message' => "Entity '".$refName."' not defined\n",
                'code' => 26,
                'column' => $columnBase + $semi + 2,
                'level' => LibxmlConstants::LIBXML_ERR_FATAL,
            ];
        }

        return null;
    }

    /** @return array{message: string, code: int, column: int, level: int} */
    private static function entityRefExpectingSemicolonError(int $column): array
    {
        return [
            'message' => "EntityRef: expecting ';'\n",
            'code' => 23,
            'column' => $column,
            'level' => LibxmlConstants::LIBXML_ERR_FATAL,
        ];
    }

    /**
     * Report VmXml well-formedness diagnostics for loadXML() documents that fail structural
     * split (php-src ext/dom/php_dom.c via libxml2; #16192, #19505).
     */
    private static function reportDomLoadXmlValidationErrors(
        Context $ctx,
        string $xml,
        ?\PHPCompiler\Frame $frame
    ): void {
        $validationErrors = VmXml::validationErrorRecords($xml);
        if ([] === $validationErrors) {
            self::reportDomLibxmlError($ctx, 'Malformed XML document', 4, 1, $frame);

            return;
        }
        foreach ($validationErrors as $validationError) {
            self::reportDomLibxmlError(
                $ctx,
                $validationError['message'],
                $validationError['code'],
                $validationError['column'],
                $frame,
                $validationError['level'],
                $validationError['line']
            );
        }
    }

    /**
     * DOMDocumentFragment::appendXML() libxml warning surface (php-src ext/dom/php_dom.c; #16162).
     */
    private static function reportDomFragmentAppendXmlError(
        Context $ctx,
        string $data,
        ?\PHPCompiler\Frame $frame
    ): void {
        $record = VmXml::validationErrorRecord($data);
        if (null === $record) {
            $record = [
                'level' => LibxmlConstants::LIBXML_ERR_FATAL,
                'code' => 4,
                'column' => 1,
                'message' => 'Malformed XML document',
                'file' => '',
                'line' => 1,
            ];
        }

        $prefix = 'DOMDocumentFragment::appendXML(): ';
        $line = $record['line'];
        VmLibxml::handleError(
            $ctx,
            $record,
            $frame,
            null,
            $prefix.'Entity: line '.$line.': parser error : '.$record['message']
        );

        $snippet = trim($data);
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix.$snippet);

        $caretColumn = self::domLibxmlCaretColumn($snippet, $record);
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix.str_repeat(' ', $caretColumn).'^');
    }

    /**
     * Caret column for DOM libxml context line (libxml xmlerror.c — 0-based offset before '^').
     */
    private static function domLibxmlCaretColumn(string $snippet, array $record): int
    {
        if ('' === $snippet) {
            return 0;
        }

        if (str_contains($record['message'], "Couldn't find end of Start Tag")) {
            return \strlen($snippet);
        }

        return max(0, $record['column'] - 1);
    }

    /**
     * DOMDocument::loadHTML() unclosed-tag libxml warnings (php-src ext/dom/php_dom.c; #16190).
     */
    private static function reportDomLoadHtmlUnclosedTagWarnings(
        Context $ctx,
        string $tagName,
        ?\PHPCompiler\Frame $frame
    ): void {
        $prefix = 'DOMDocument::loadHTML(): ';
        $record = [
            'level' => LibxmlConstants::LIBXML_ERR_ERROR,
            'code' => 73,
            'column' => 1,
            'message' => "Tag {$tagName} invalid",
            'file' => '',
            'line' => 1,
        ];
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix."Tag {$tagName} invalid in Entity, line: 1");
        VmLibxml::handleError(
            $ctx,
            $record,
            $frame,
            null,
            $prefix."Couldn't find end of Start Tag {$tagName} in Entity, line: 1"
        );
    }

    private static function approximateXmlColumn(string $xml, string $needle): int
    {
        $pos = strpos($xml, $needle);
        if (false === $pos) {
            return 1;
        }

        return $pos + 1;
    }

    private static function parseDoctypeName(string $xml): ?string
    {
        if (!preg_match('/<!DOCTYPE\s+([A-Za-z_][\w:.-]*)/', $xml, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return array{name: string, publicId: string, systemId: string}|null
     */
    private static function parseDoctypeDeclaration(string $xml): ?array
    {
        $trimmed = ltrim($xml);
        if (!preg_match('/^<!DOCTYPE\s+/i', $trimmed)) {
            return null;
        }
        // Optional internal subset after PUBLIC/SYSTEM (libxml; #20504 / re-#15292).
        if (preg_match(
            '/^<!DOCTYPE\s+([A-Za-z_][\w:.-]*)\s+PUBLIC\s+"([^"]*)"\s+"([^"]*)"(?:\s*\[[^\]]*\])?\s*>/is',
            $trimmed,
            $match
        )) {
            return [
                'name' => $match[1],
                'publicId' => $match[2],
                'systemId' => $match[3],
            ];
        }
        if (preg_match(
            '/^<!DOCTYPE\s+([A-Za-z_][\w:.-]*)\s+SYSTEM\s+"([^"]*)"(?:\s*\[[^\]]*\])?\s*>/is',
            $trimmed,
            $match
        )) {
            return [
                'name' => $match[1],
                'publicId' => '',
                'systemId' => $match[2],
            ];
        }
        if (preg_match('/^<!DOCTYPE\s+([A-Za-z_][\w:.-]*)\s*>/is', $trimmed, $match)) {
            return [
                'name' => $match[1],
                'publicId' => '',
                'systemId' => '',
            ];
        }
        if (preg_match('/^<!DOCTYPE\s+([A-Za-z_][\w:.-]*)\s*\[[^\]]*\]\s*>/is', $trimmed, $match)) {
            return [
                'name' => $match[1],
                'publicId' => '',
                'systemId' => '',
            ];
        }

        return null;
    }

    /**
     * @return array{name: string, publicId: string, systemId: string}|null
     */
    private static function parseHtmlDoctypeDeclaration(string $html): ?array
    {
        return self::parseDoctypeDeclaration($html);
    }

    private static function attachDoctypeChild(
        Context $ctx,
        ObjectEntry $document,
        string $name,
        string $publicId,
        string $systemId
    ): ObjectEntry {
        $doctypeVar = self::createDocumentType(
            $ctx,
            $name,
            $publicId,
            $systemId,
            $document,
            VmDomLiving::isLivingDocument($document)
        );
        $doctype = $doctypeVar->toObject();
        self::adoptDocumentTypeIntoDocument($doctype, $document);
        $state = DomRegistry::state($document);
        // Prepend doctype ahead of existing element children (php-src append order).
        $without = [];
        foreach ($state->childIds as $childId) {
            if ($childId !== $doctype->id) {
                $without[] = $childId;
            }
        }
        $state->childIds = array_merge([$doctype->id], $without);

        return $doctype;
    }

    /**
     * Attach an orphan (or re-parented) DocumentType to a document (php-src createDocument / parse; #20910).
     */
    private static function adoptDocumentTypeIntoDocument(ObjectEntry $doctype, ObjectEntry $document): void
    {
        $dt = DomRegistry::state($doctype);
        $docState = DomRegistry::state($document);
        $docState->doctypeName = $dt->nodeName;
        $docState->doctypePublicId = $dt->publicId;
        $docState->doctypeSystemId = $dt->systemId;
        $docState->doctypeId = $doctype->id;

        $oldParentId = $dt->parentId;
        if (null !== $oldParentId && $oldParentId !== $document->id) {
            $oldParent = DomRegistry::entry($oldParentId);
            if (null !== $oldParent) {
                $oldState = DomRegistry::state($oldParent);
                $oldState->childIds = array_values(array_filter(
                    $oldState->childIds,
                    static fn (int $id): bool => $id !== $doctype->id
                ));
                if ($oldState->doctypeId === $doctype->id) {
                    $oldState->doctypeId = null;
                    $oldState->doctypeName = null;
                    $oldState->doctypePublicId = null;
                    $oldState->doctypeSystemId = null;
                }
            }
        }

        self::linkChildToParent($doctype, $document);
        self::propagateDocumentId($doctype, $document->id);
    }

    public static function createProcessingInstruction(
        Context $ctx,
        string $target,
        string $data,
        ObjectEntry $ownerDocument
    ): ObjectEntry {
        self::assertValidProcessingInstructionTarget($target);
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_PROCESSING_INSTRUCTION);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_PROCESSING_INSTRUCTION_NODE;
        $state->nodeName = $target;
        $state->textContent = $data;
        $state->documentId = $ownerDocument->id;
        DomRegistry::attach($entry, $state);
        $entry->getProperty(self::PROP_NODE_NAME)->string($target);
        $entry->getProperty(self::PROP_NODE_VALUE)->string($data);
        $entry->getProperty(self::PROP_TARGET)->string($target);
        $entry->getProperty(self::PROP_DATA)->string($data);
        self::initNodePropertySlots($entry);

        return $entry;
    }

    /**
     * @return array<string, true>
     */
    private static function parseDoctypeElementDeclarations(string $xml): array
    {
        $declared = [];
        $subset = self::extractDoctypeInternalSubset($xml);
        if (null === $subset) {
            return $declared;
        }
        if (!preg_match_all('/<!ELEMENT\s+(\S+)\s+/', $subset, $matches)) {
            return $declared;
        }
        foreach ($matches[1] as $name) {
            $declared[$name] = true;
        }

        return $declared;
    }

    /**
     * @return list<string>
     */
    private static function collectElementNames(ObjectEntry $root): array
    {
        /** @var array<string, true> $names */
        $names = [];
        self::collectElementNamesRecursive($root, $names);

        return array_keys($names);
    }

    /**
     * @param array<string, true> $names
     */
    private static function collectElementNamesRecursive(ObjectEntry $node, array &$names): void
    {
        if (!self::isElement($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        $names[$state->nodeName] = true;
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementNamesRecursive($child, $names);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseDoctypeIdAttributes(string $xml): array
    {
        $idAttrs = [];
        $subset = self::extractDoctypeInternalSubset($xml);
        if (null === $subset) {
            return $idAttrs;
        }
        if (!preg_match_all('/<!ATTLIST\s+(\S+)\s+(\S+)\s+ID\b/', $subset, $matches, PREG_SET_ORDER)) {
            return $idAttrs;
        }
        foreach ($matches as $match) {
            $idAttrs[$match[1]] = $match[2];
        }

        return $idAttrs;
    }

    /**
     * ATTLIST default / #FIXED values from the internal subset (libxml XML_PARSE_DTDATTR;
     * php-src ext/dom/document.c loadXML; #21456).
     *
     * @return array<string, array<string, string>> elementName => (attrName => defaultValue)
     */
    private static function parseDoctypeDefaultAttributes(string $xml): array
    {
        $defaults = [];
        $subset = self::extractDoctypeInternalSubset($xml);
        if (null === $subset) {
            return $defaults;
        }
        if (!preg_match_all('/<!ATTLIST\s+(\S+)\s+([^>]*)>/is', $subset, $blocks, PREG_SET_ORDER)) {
            return $defaults;
        }
        foreach ($blocks as $block) {
            $elementName = $block[1];
            $body = $block[2];
            $pos = 0;
            $len = \strlen($body);
            while ($pos < $len) {
                if (!preg_match('/\G\s*(\S+)\s+/A', $body, $attrMatch, 0, $pos)) {
                    break;
                }
                $attrName = $attrMatch[1];
                $pos += \strlen($attrMatch[0]);
                if (preg_match('/\GNOTATION\s*\([^)]*\)/A', $body, $typeMatch, 0, $pos)
                    || preg_match('/\G\([^)]*\)/A', $body, $typeMatch, 0, $pos)
                    || preg_match('/\G\S+/A', $body, $typeMatch, 0, $pos)
                ) {
                    $pos += \strlen($typeMatch[0]);
                } else {
                    break;
                }
                if (!preg_match(
                    '/\G\s*(#REQUIRED|#IMPLIED|#FIXED\s+"([^"]*)"|#FIXED\s+\'([^\']*)\'|"([^"]*)"|\'([^\']*)\')/A',
                    $body,
                    $defMatch,
                    0,
                    $pos
                )) {
                    break;
                }
                $pos += \strlen($defMatch[0]);
                $decl = $defMatch[1];
                if ('#REQUIRED' === $decl || '#IMPLIED' === $decl) {
                    continue;
                }
                $value = null;
                if (preg_match('/^#FIXED\s+"([^"]*)"$/', $decl, $vm)
                    || preg_match('/^#FIXED\s+\'([^\']*)\'$/', $decl, $vm)
                    || preg_match('/^"([^"]*)"$/', $decl, $vm)
                    || preg_match('/^\'([^\']*)\'$/', $decl, $vm)
                ) {
                    $value = $vm[1];
                }
                if (null === $value) {
                    continue;
                }
                if (!isset($defaults[$elementName])) {
                    $defaults[$elementName] = [];
                }
                // First declaration wins (libxml keeps the first ATTLIST default for a name).
                if (!\array_key_exists($attrName, $defaults[$elementName])) {
                    $defaults[$elementName][$attrName] = $value;
                }
            }
        }

        return $defaults;
    }

    /**
     * Synthesize missing attributes from ATTLIST defaults (#21456). Does not overwrite
     * attributes present in the instance markup.
     *
     * @param array<string, array<string, string>> $defaults
     */
    private static function applyDtdDefaultAttributesToSubtree(ObjectEntry $node, array $defaults): void
    {
        if (self::isElement($node)) {
            $state = DomRegistry::state($node);
            $elDefaults = $defaults[$state->nodeName] ?? null;
            if (null !== $elDefaults) {
                foreach ($elDefaults as $attrName => $value) {
                    if (!\array_key_exists($attrName, $state->attributes)) {
                        $state->attributes[$attrName] = $value;
                        if (str_starts_with($attrName, 'xml:')) {
                            $state->attributeNamespaces[$attrName] = self::XML_NAMESPACE_URI;
                        }
                    }
                }
            }
        }
        $state = DomRegistry::state($node);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::applyDtdDefaultAttributesToSubtree($child, $defaults);
            }
        }
    }

    /**
     * @return array<string, string> general entity name => replacement text
     */
    private static function parseDoctypeGeneralEntities(
        Context $ctx,
        string $xml,
        bool $substituteEntities = false,
        ?\PHPCompiler\Frame $frame = null
    ): array {
        $entities = [];
        $subset = self::extractDoctypeInternalSubset($xml);
        if (null === $subset) {
            return $entities;
        }
        // Internal parsed entities first (declaration order: first wins).
        if (preg_match_all('/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+"([^"]*)"\s*>/', $subset, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!isset($entities[$match[1]])) {
                    $entities[$match[1]] = $match[2];
                }
            }
        }
        // External SYSTEM entities (skip NDATA unparsed — those end with NDATA name>).
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+SYSTEM\s+"([^"]*)"\s*>/',
            $subset,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                if (isset($entities[$match[1]])) {
                    continue;
                }
                $entities[$match[1]] = $substituteEntities
                    ? self::resolveExternalGeneralEntityReplacement($ctx, null, $match[2], $frame)
                    : '';
            }
        }
        // External PUBLIC entities (skip PUBLIC+NDATA unparsed).
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+PUBLIC\s+"([^"]*)"\s+"([^"]*)"\s*>/',
            $subset,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                if (isset($entities[$match[1]])) {
                    continue;
                }
                $entities[$match[1]] = $substituteEntities
                    ? self::resolveExternalGeneralEntityReplacement($ctx, $match[2], $match[3], $frame)
                    : '';
            }
        }

        return $entities;
    }

    /**
     * LIBXML_NOENT external entity body — empty string on load failure (Zend leaves no text child).
     *
     * php-src ext/libxml/libxml.c + ext/dom/document.c (#21599).
     */
    private static function resolveExternalGeneralEntityReplacement(
        Context $ctx,
        ?string $publicId,
        string $systemId,
        ?\PHPCompiler\Frame $frame
    ): string {
        $content = VmLibxml::resolveExternalEntityContent($ctx, $publicId, $systemId, $frame);

        return null === $content ? '' : $content;
    }

    private static function extractDoctypeInternalSubset(string $xml): ?string
    {
        // Optional PUBLIC/SYSTEM external ID before brackets (php-src / libxml; #21000).
        if (!preg_match(
            '/<!DOCTYPE\s+\S+(?:\s+PUBLIC\s+"[^"]*"\s+"[^"]*"|\s+SYSTEM\s+"[^"]*")?\s*\[(.*)\]\s*>/is',
            $xml,
            $match
        )) {
            return null;
        }

        return $match[1];
    }

    /**
     * Serialize internal subset like libxml intSubset dump (php-src documenttype.c; #21000).
     *
     * Markup declarations get a trailing newline; comments are concatenated without one.
     * Empty / whitespace-only subsets → null.
     */
    private static function formatDoctypeInternalSubsetProperty(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed) {
            return null;
        }
        if (!preg_match_all('/<!--.*?-->|<![^>]*>/s', $trimmed, $matches)) {
            return null;
        }
        $out = '';
        foreach ($matches[0] as $token) {
            if (str_starts_with($token, '<!--')) {
                $out .= $token;
            } else {
                $out .= $token."\n";
            }
        }

        return '' === $out ? null : $out;
    }

    private static function populateDoctypeInternalSubset(
        Context $ctx,
        ObjectEntry $doctype,
        ObjectEntry $document,
        string $xml
    ): void {
        $subset = self::extractDoctypeInternalSubset($xml);
        $formatted = null !== $subset ? self::formatDoctypeInternalSubsetProperty($subset) : null;
        $doctypeState = DomRegistry::state($doctype);
        $doctypeState->internalSubset = $formatted;
        if (null === $formatted) {
            $doctype->getProperty(self::PROP_INTERNAL_SUBSET)->null();
        } else {
            $doctype->getProperty(self::PROP_INTERNAL_SUBSET)->string($formatted);
        }
        if (null === $subset) {
            return;
        }

        /** @var list<int> $entityIds */
        $entityIds = [];
        // General entities only (skip parameter entities `<!ENTITY % …>`). Order: NDATA → external → internal (#20734).
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+PUBLIC\s+"([^"]*)"\s+"([^"]*)"\s+NDATA\s+([A-Za-z_][\w:.-]*)\s*>/',
            $subset,
            $entityMatches,
            PREG_SET_ORDER
        )) {
            foreach ($entityMatches as $match) {
                $entity = self::createEntityDeclaration(
                    $ctx,
                    $match[1],
                    null,
                    $match[2],
                    $match[3],
                    $match[4],
                    $document
                );
                $entityIds[] = $entity->id;
            }
        }
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+SYSTEM\s+"([^"]*)"\s+NDATA\s+([A-Za-z_][\w:.-]*)\s*>/',
            $subset,
            $entityMatches,
            PREG_SET_ORDER
        )) {
            foreach ($entityMatches as $match) {
                $entity = self::createEntityDeclaration(
                    $ctx,
                    $match[1],
                    null,
                    null,
                    $match[2],
                    $match[3],
                    $document
                );
                $entityIds[] = $entity->id;
            }
        }
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+PUBLIC\s+"([^"]*)"\s+"([^"]*)"\s*>/',
            $subset,
            $entityMatches,
            PREG_SET_ORDER
        )) {
            foreach ($entityMatches as $match) {
                // Skip when already captured as PUBLIC+NDATA (same PUBLIC "…" "…" prefix).
                if (self::namedNodeMapListContainsName($entityIds, $match[1])) {
                    continue;
                }
                // External parsed entity: present in map, but php-src entity.c nulls publicId/systemId
                // unless etype is XML_EXTERNAL_GENERAL_UNPARSED_ENTITY (#20734).
                $entity = self::createEntityDeclaration(
                    $ctx,
                    $match[1],
                    null,
                    null,
                    null,
                    null,
                    $document
                );
                $entityIds[] = $entity->id;
            }
        }
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+SYSTEM\s+"([^"]*)"\s*>/',
            $subset,
            $entityMatches,
            PREG_SET_ORDER
        )) {
            foreach ($entityMatches as $match) {
                if (self::namedNodeMapListContainsName($entityIds, $match[1])) {
                    continue;
                }
                $entity = self::createEntityDeclaration(
                    $ctx,
                    $match[1],
                    null,
                    null,
                    null,
                    null,
                    $document
                );
                $entityIds[] = $entity->id;
            }
        }
        if (preg_match_all(
            '/<!ENTITY\s+(?!%)([A-Za-z_][\w:.-]*)\s+"([^"]*)"\s*>/',
            $subset,
            $entityMatches,
            PREG_SET_ORDER
        )) {
            foreach ($entityMatches as $match) {
                if (self::namedNodeMapListContainsName($entityIds, $match[1])) {
                    continue;
                }
                $entity = self::createEntityDeclaration(
                    $ctx,
                    $match[1],
                    $match[2],
                    null,
                    null,
                    null,
                    $document
                );
                $entityIds[] = $entity->id;
            }
        }

        /** @var list<int> $notationIds */
        $notationIds = [];
        if (preg_match_all(
            '/<!NOTATION\s+([A-Za-z_][\w:.-]*)\s+PUBLIC\s+"([^"]*)"\s+"([^"]*)"\s*>/',
            $subset,
            $notationMatches,
            PREG_SET_ORDER
        )) {
            foreach ($notationMatches as $match) {
                $notation = self::createNotationDeclaration(
                    $ctx,
                    $match[1],
                    $match[2],
                    $match[3],
                    $document
                );
                $notationIds[] = $notation->id;
            }
        }
        if (preg_match_all(
            '/<!NOTATION\s+([A-Za-z_][\w:.-]*)\s+SYSTEM\s+"([^"]*)"\s*>/',
            $subset,
            $notationMatches,
            PREG_SET_ORDER
        )) {
            foreach ($notationMatches as $match) {
                $notation = self::createNotationDeclaration(
                    $ctx,
                    $match[1],
                    '',
                    $match[2],
                    $document
                );
                $notationIds[] = $notation->id;
            }
        }

        $entitiesMap = self::createNamedNodeMap($ctx, $entityIds, $doctype, true);
        $doctypeState->entitiesMapId = $entitiesMap->toObject()->id;
        $doctype->getProperty(self::PROP_ENTITIES)->copyFrom($entitiesMap);

        $notationsMap = self::createNamedNodeMap($ctx, $notationIds, $doctype, true);
        $doctypeState->notationsMapId = $notationsMap->toObject()->id;
        $doctype->getProperty(self::PROP_NOTATIONS)->copyFrom($notationsMap);
    }

    /**
     * @param list<int> $nodeIds
     */
    private static function namedNodeMapListContainsName(array $nodeIds, string $name): bool
    {
        foreach ($nodeIds as $nodeId) {
            $node = DomRegistry::entry($nodeId);
            if (null !== $node && DomRegistry::state($node)->nodeName === $name) {
                return true;
            }
        }

        return false;
    }

    public static function createEntityDeclaration(
        Context $ctx,
        string $name,
        ?string $replacementText,
        ?string $publicId,
        ?string $systemId,
        ?string $notationName,
        ObjectEntry $ownerDocument
    ): ObjectEntry {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ENTITY);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ENTITY_DECL_NODE;
        $state->nodeName = $name;
        $state->entityReplacementText = $replacementText;
        $state->publicId = $publicId;
        $state->systemId = $systemId;
        $state->notationName = $notationName;
        $state->documentId = $ownerDocument->id;
        DomRegistry::attach($entry, $state);
        self::initEntityPropertySlots(
            $entry,
            $name,
            $publicId ?? '',
            $systemId ?? '',
            $notationName
        );

        return $entry;
    }

    public static function createNotationDeclaration(
        Context $ctx,
        string $name,
        string $publicId,
        string $systemId,
        ObjectEntry $ownerDocument
    ): ObjectEntry {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_NOTATION);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NOTATION_NODE;
        $state->nodeName = $name;
        $state->publicId = $publicId;
        $state->systemId = $systemId;
        $state->documentId = $ownerDocument->id;
        DomRegistry::attach($entry, $state);
        self::initNotationPropertySlots($entry, $name, $publicId, $systemId);

        return $entry;
    }

    private static function initEntityPropertySlots(
        ObjectEntry $entry,
        string $name,
        string $publicId,
        string $systemId,
        ?string $notationName
    ): void {
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        if ('' === $publicId) {
            $entry->getProperty(self::PROP_PUBLIC_ID)->null();
        } else {
            $entry->getProperty(self::PROP_PUBLIC_ID)->string($publicId);
        }
        if ('' === $systemId) {
            $entry->getProperty(self::PROP_SYSTEM_ID)->null();
        } else {
            $entry->getProperty(self::PROP_SYSTEM_ID)->string($systemId);
        }
        if (null === $notationName || '' === $notationName) {
            $entry->getProperty(self::PROP_NOTATION_NAME)->null();
        } else {
            $entry->getProperty(self::PROP_NOTATION_NAME)->string($notationName);
        }
        self::initNodePropertySlots($entry);
    }

    private static function initNotationPropertySlots(
        ObjectEntry $entry,
        string $name,
        string $publicId,
        string $systemId
    ): void {
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        if ('' === $publicId) {
            $entry->getProperty(self::PROP_PUBLIC_ID)->null();
        } else {
            $entry->getProperty(self::PROP_PUBLIC_ID)->string($publicId);
        }
        if ('' === $systemId) {
            $entry->getProperty(self::PROP_SYSTEM_ID)->null();
        } else {
            $entry->getProperty(self::PROP_SYSTEM_ID)->string($systemId);
        }
        self::initNodePropertySlots($entry);
    }

    /**
     * @param array<string, string> $generalEntities
     *
     * With LIBXML_NOENT (XML_PARSE_NOENT), general entity refs become text like libxml2
     * (#19796, php-src ext/dom/document.c). Adjacent text + substituted entities merge.
     */
    /**
     * @param array<string, string> $generalEntities
     */
    private static function appendParsedTextOrEntityRefs(
        Context $ctx,
        ObjectEntry $parent,
        string $text,
        ?ObjectEntry $ownerDocument,
        array $generalEntities,
        bool $substituteEntities = false,
        ?string $sourceXml = null,
        int $textBaseOffset = 0
    ): void {
        if ('' === $text) {
            return;
        }
        $state = DomRegistry::state($parent);
        $pos = 0;
        $len = \strlen($text);
        $buffer = '';
        $bufferStart = 0;
        while ($pos < $len) {
            $amp = strpos($text, '&', $pos);
            if (false === $amp) {
                if ('' === $buffer) {
                    $bufferStart = $pos;
                }
                $buffer .= substr($text, $pos);
                break;
            }
            if ($amp > $pos) {
                if ('' === $buffer) {
                    $bufferStart = $pos;
                }
                $buffer .= substr($text, $pos, $amp - $pos);
            }
            $semi = strpos($text, ';', $amp + 1);
            if (false === $semi) {
                if ('' === $buffer) {
                    $bufferStart = $amp;
                }
                $buffer .= substr($text, $amp);
                break;
            }
            $refName = substr($text, $amp + 1, $semi - $amp - 1);
            if (isset($generalEntities[$refName])) {
                if ($substituteEntities) {
                    // Fold replacement into the text buffer (libxml NOENT merge).
                    if ('' === $buffer) {
                        $bufferStart = $amp;
                    }
                    $buffer .= self::expandGeneralEntityReplacement(
                        $generalEntities[$refName],
                        $generalEntities
                    );
                } else {
                    if ('' !== $buffer) {
                        $textNode = self::createTextNode($ctx, self::decodePredefinedXmlEntities($buffer), $ownerDocument);
                        // libxml xmlGetLineNo for text uses the line after consuming the chars (#20795).
                        self::assignLineNoFromSource(
                            $textNode,
                            $sourceXml,
                            $textBaseOffset + $bufferStart + \strlen($buffer)
                        );
                        $state->childIds[] = $textNode->id;
                        self::linkChildToParent($textNode, $parent);
                        $buffer = '';
                    }
                    $entityRef = self::createEntityReferenceFromLoad(
                        $ctx,
                        $refName,
                        $generalEntities[$refName],
                        $ownerDocument
                    );
                    self::assignLineNoFromSource($entityRef, $sourceXml, $textBaseOffset + $amp);
                    $state->childIds[] = $entityRef->id;
                    self::linkChildToParent($entityRef, $parent);
                }
            } else {
                // Predefined + numeric char refs (#21034 helper); undeclared named refs are
                // rejected earlier in loadXML (#22774) — keep raw only for non-load callers.
                $decoded = self::decodeXmlContentEntityRef($refName);
                if (null !== $decoded) {
                    if ('' === $buffer) {
                        $bufferStart = $amp;
                    }
                    $buffer .= $decoded;
                } else {
                    if ('' === $buffer) {
                        $bufferStart = $amp;
                    }
                    $buffer .= substr($text, $amp, $semi - $amp + 1);
                }
            }
            $pos = $semi + 1;
        }
        if ('' !== $buffer) {
            $textNode = self::createTextNode($ctx, self::decodePredefinedXmlEntities($buffer), $ownerDocument);
            self::assignLineNoFromSource(
                $textNode,
                $sourceXml,
                $textBaseOffset + $bufferStart + \strlen($buffer)
            );
            $state->childIds[] = $textNode->id;
            self::linkChildToParent($textNode, $parent);
        }
    }

    /** libxml xmlGetLineNo — set DomNodeState::$lineNo from source byte offset (#20795). */
    private static function assignLineNoFromSource(ObjectEntry $node, ?string $sourceXml, int $offset): void
    {
        if (null === $sourceXml) {
            return;
        }
        DomRegistry::state($node)->lineNo = self::lineNoAtOffset($sourceXml, $offset);
    }

    /**
     * Expand nested general-entity refs inside a DTD replacement string (LIBXML_NOENT).
     *
     * @param array<string, string> $generalEntities
     */
    private static function expandGeneralEntityReplacement(string $replacement, array $generalEntities): string
    {
        if ('' === $replacement || !str_contains($replacement, '&')) {
            return $replacement;
        }
        $pos = 0;
        $len = \strlen($replacement);
        $out = '';
        $guard = 0;
        while ($pos < $len) {
            if (++$guard > 1024) {
                $out .= substr($replacement, $pos);
                break;
            }
            $amp = strpos($replacement, '&', $pos);
            if (false === $amp) {
                $out .= substr($replacement, $pos);
                break;
            }
            if ($amp > $pos) {
                $out .= substr($replacement, $pos, $amp - $pos);
            }
            $semi = strpos($replacement, ';', $amp + 1);
            if (false === $semi) {
                $out .= substr($replacement, $amp);
                break;
            }
            $refName = substr($replacement, $amp + 1, $semi - $amp - 1);
            if (isset($generalEntities[$refName])) {
                $out .= self::expandGeneralEntityReplacement($generalEntities[$refName], $generalEntities);
            } else {
                $decoded = self::decodePredefinedXmlEntity($refName);
                $out .= null !== $decoded
                    ? $decoded
                    : substr($replacement, $amp, $semi - $amp + 1);
            }
            $pos = $semi + 1;
        }

        return $out;
    }

    private static function createEntityReferenceFromLoad(
        Context $ctx,
        string $name,
        string $replacementText,
        ?ObjectEntry $ownerDocument
    ): ObjectEntry {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ENTITY_REFERENCE);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        self::initNodePropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ENTITY_REF_NODE;
        $state->nodeName = $name;
        $state->entityReplacementText = $replacementText;
        if (null !== $ownerDocument) {
            $state->documentId = $ownerDocument->id;
        }
        DomRegistry::attach($entry, $state);

        return $entry;
    }

    private static function decodePredefinedXmlEntities(string $text): string
    {
        $pos = 0;
        $len = \strlen($text);
        $out = '';
        while ($pos < $len) {
            $amp = strpos($text, '&', $pos);
            if (false === $amp) {
                $out .= substr($text, $pos);
                break;
            }
            if ($amp > $pos) {
                $out .= substr($text, $pos, $amp - $pos);
            }
            $semi = strpos($text, ';', $amp + 1);
            if (false === $semi) {
                $out .= substr($text, $amp);
                break;
            }
            $refName = substr($text, $amp + 1, $semi - $amp - 1);
            $decoded = self::decodePredefinedXmlEntity($refName);
            if (null !== $decoded) {
                $out .= $decoded;
            } else {
                $out .= substr($text, $amp, $semi - $amp + 1);
            }
            $pos = $semi + 1;
        }

        return $out;
    }

    /**
     * Expand HTML character references the way libxml htmlReadMemory does (php-src html_document.c; #20260).
     *
     * ENT_HTML401 matches libxml's HTML named-entity set (&eacute;, &amp;, …); ENT_QUOTES covers attr values.
     */
    public static function decodeHtmlCharacterReferences(string $text): string
    {
        if ('' === $text || false === strpos($text, '&')) {
            return $text;
        }

        return VmString::html_entity_decode($text, ENT_QUOTES | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, string> $attributes
     *
     * @return array<string, string>
     */
    private static function decodeHtmlAttributeMap(array $attributes): array
    {
        foreach ($attributes as $name => $value) {
            $attributes[$name] = self::decodeHtmlCharacterReferences($value);
        }

        return $attributes;
    }

    private static function decodePredefinedXmlEntity(string $name): ?string
    {
        return match ($name) {
            'amp' => '&',
            'lt' => '<',
            'gt' => '>',
            'quot' => '"',
            'apos' => "'",
            default => null,
        };
    }

    private static function stripDoctype(string $xml): string
    {
        return self::stripDoctypeWithOffset($xml)[0];
    }

    /**
     * Split an XML document into Misc nodes, optional DOCTYPE, root element, and trailing Misc (XML 1.0; #19361).
     *
     * @return null|array{
     *   miscBefore: list<array{kind: 'comment'|'pi', data: string, target?: string}>,
     *   doctype: null|array{name: string, publicId: string, systemId: string},
     *   miscBetween: list<array{kind: 'comment'|'pi', data: string, target?: string}>,
     *   rootXml: string,
     *   rootOffset: int,
     *   miscAfter: list<array{kind: 'comment'|'pi', data: string, target?: string}>
     * }
     */
    private static function splitXmlDocumentParts(string $xml): ?array
    {
        $pos = 0;
        $len = \strlen($xml);
        $pos = self::skipXmlDocumentWhitespace($xml, $pos);
        if (preg_match('/\G<\?xml\s[^?]*\?>/is', $xml, $decl, 0, $pos)) {
            $pos += \strlen($decl[0]);
            $pos = self::skipXmlDocumentWhitespace($xml, $pos);
        }

        $miscBefore = [];
        while ($pos < $len) {
            $misc = self::parseDocumentMiscAt($xml, $pos);
            if (null === $misc) {
                break;
            }
            $miscBefore[] = $misc['node'];
            $pos = self::skipXmlDocumentWhitespace($xml, $misc['end']);
        }

        $doctype = null;
        $doctypeDecl = self::parseDoctypeDeclaration(substr($xml, $pos));
        if (null !== $doctypeDecl) {
            $doctypeEnd = self::findXmlDoctypeEnd($xml, $pos);
            if (null === $doctypeEnd) {
                return null;
            }
            $doctype = $doctypeDecl;
            $pos = self::skipXmlDocumentWhitespace($xml, $doctypeEnd);
        }

        $miscBetween = [];
        while ($pos < $len) {
            $misc = self::parseDocumentMiscAt($xml, $pos);
            if (null === $misc) {
                break;
            }
            $miscBetween[] = $misc['node'];
            $pos = self::skipXmlDocumentWhitespace($xml, $misc['end']);
        }

        if ($pos >= $len || '<' !== $xml[$pos]) {
            return null;
        }
        $rootOffset = $pos;
        $rootEnd = self::findElementEnd($xml, $pos);
        if (null === $rootEnd) {
            return null;
        }
        $rootXml = substr($xml, $rootOffset, $rootEnd - $rootOffset);
        $pos = self::skipXmlDocumentWhitespace($xml, $rootEnd);

        $miscAfter = [];
        while ($pos < $len) {
            $misc = self::parseDocumentMiscAt($xml, $pos);
            if (null === $misc) {
                break;
            }
            $miscAfter[] = $misc['node'];
            $pos = self::skipXmlDocumentWhitespace($xml, $misc['end']);
        }
        if ($pos < $len) {
            return null;
        }

        return [
            'miscBefore' => $miscBefore,
            'doctype' => $doctype,
            'miscBetween' => $miscBetween,
            'rootXml' => $rootXml,
            'rootOffset' => $rootOffset,
            'miscAfter' => $miscAfter,
        ];
    }

    private static function skipXmlDocumentWhitespace(string $xml, int $pos): int
    {
        $len = \strlen($xml);
        while ($pos < $len && 1 === preg_match('/\s/', $xml[$pos])) {
            ++$pos;
        }

        return $pos;
    }

    /**
     * @return null|array{end: int, node: array{kind: 'comment'|'pi', data: string, target?: string}}
     */
    private static function parseDocumentMiscAt(string $xml, int $pos): ?array
    {
        $comment = VmXml::parseCommentAt($xml, $pos);
        if (null !== $comment) {
            return [
                'end' => $comment['end'],
                'node' => ['kind' => 'comment', 'data' => $comment['data']],
            ];
        }
        $pi = VmXml::parseProcessingInstructionAt($xml, $pos);
        if (null !== $pi) {
            return [
                'end' => $pi['end'],
                'node' => ['kind' => 'pi', 'data' => $pi['data'], 'target' => $pi['target']],
            ];
        }

        return null;
    }

    /** @return null|int byte offset after <!DOCTYPE …> at $pos */
    private static function findXmlDoctypeEnd(string $xml, int $pos): ?int
    {
        if (!preg_match('/\G<!DOCTYPE\s/i', $xml, $open, 0, $pos)) {
            return null;
        }
        unset($open);
        $len = \strlen($xml);
        $i = $pos + 9;
        $bracketDepth = 0;
        while ($i < $len) {
            $ch = $xml[$i];
            if ('[' === $ch) {
                ++$bracketDepth;
            } elseif (']' === $ch && $bracketDepth > 0) {
                --$bracketDepth;
            } elseif ('>' === $ch && 0 === $bracketDepth) {
                return $i + 1;
            }
            ++$i;
        }

        return null;
    }

    /**
     * @param array{kind: 'comment'|'pi', data: string, target?: string} $misc
     */
    private static function attachDocumentMiscChild(
        Context $ctx,
        ObjectEntry $document,
        array $misc
    ): ObjectEntry {
        if ('pi' === $misc['kind']) {
            $node = self::createProcessingInstruction(
                $ctx,
                $misc['target'] ?? '',
                $misc['data'],
                $document
            );
        } else {
            $node = self::createComment($ctx, $misc['data'], $document);
        }
        self::linkChildToParent($node, $document);
        self::propagateDocumentId($node, $document->id);

        return $node;
    }

    /**
     * @return array{0: string, 1: int} element XML and byte offset in $xml for line numbers (#15290)
     */
    private static function stripDoctypeWithOffset(string $xml): array
    {
        $parts = self::splitXmlDocumentParts($xml);
        if (null !== $parts) {
            return [$parts['rootXml'], $parts['rootOffset']];
        }
        $offset = 0;
        if (preg_match('/^\s*<\?xml[^?]*\?>\s*/s', $xml, $match)) {
            $offset += \strlen($match[0]);
            $xml = substr($xml, \strlen($match[0]));
        }
        if (preg_match('/^\s*<!DOCTYPE\s+\S+\s*\[[^\]]*\]\s*>\s*/s', $xml, $match)) {
            $offset += \strlen($match[0]);
            $xml = substr($xml, \strlen($match[0]));
        }
        if (preg_match('/^\s*<!DOCTYPE[^>]*>\s*/s', $xml, $match)) {
            $offset += \strlen($match[0]);
            $xml = substr($xml, \strlen($match[0]));
        }
        $leading = \strlen($xml) - \strlen(ltrim($xml));
        $offset += $leading;
        $xml = ltrim($xml);

        return [rtrim($xml), $offset];
    }

    private static function lineNoAtOffset(string $sourceXml, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($sourceXml, 0, $offset), "\n") + 1;
    }

    /**
     * Parse HTML/XML markup attribute substring (libxml HTML semantics; #18319).
     *
     * Supports double-quoted, single-quoted, and unquoted HTML attribute values.
     * Valueless names (`hidden`, `disabled`, …) become empty-string values per
     * WHATWG empty attribute syntax / php-src html5_parser (#26099).
     *
     * @return array<string, string>
     */
    public static function parseMarkupAttributes(string $attrString): array
    {
        $attrs = [];
        if ('' === $attrString) {
            return $attrs;
        }
        $len = \strlen($attrString);
        $pos = 0;
        while ($pos < $len) {
            while ($pos < $len && ctype_space($attrString[$pos])) {
                ++$pos;
            }
            if ($pos >= $len) {
                break;
            }
            if (!self::isMarkupAttributeNameStart($attrString[$pos])) {
                ++$pos;

                continue;
            }
            $nameStart = $pos;
            ++$pos;
            while ($pos < $len && self::isMarkupAttributeNameChar($attrString[$pos])) {
                ++$pos;
            }
            $name = substr($attrString, $nameStart, $pos - $nameStart);
            while ($pos < $len && ctype_space($attrString[$pos])) {
                ++$pos;
            }
            if ($pos >= $len || '=' !== $attrString[$pos]) {
                // HTML empty attribute syntax — keep name with '' value (#26099).
                $attrs[$name] = '';

                continue;
            }
            ++$pos;
            while ($pos < $len && ctype_space($attrString[$pos])) {
                ++$pos;
            }
            if ($pos >= $len) {
                break;
            }
            $quote = $attrString[$pos];
            if ('"' === $quote || "'" === $quote) {
                ++$pos;
                $valueStart = $pos;
                while ($pos < $len && $attrString[$pos] !== $quote) {
                    ++$pos;
                }
                $attrs[$name] = substr($attrString, $valueStart, $pos - $valueStart);
                if ($pos < $len) {
                    ++$pos;
                }

                continue;
            }
            $valueStart = $pos;
            while ($pos < $len
                && !ctype_space($attrString[$pos])
                && '>' !== $attrString[$pos]
                && '/' !== $attrString[$pos]
            ) {
                ++$pos;
            }
            $attrs[$name] = substr($attrString, $valueStart, $pos - $valueStart);
        }

        return $attrs;
    }

    private static function isMarkupAttributeNameStart(string $char): bool
    {
        return (bool) preg_match('/[A-Za-z_]/', $char);
    }

    private static function isMarkupAttributeNameChar(string $char): bool
    {
        return (bool) preg_match('/[\w:.-]/', $char);
    }

    /**
     * @return array<string, string>
     */
    private static function parseAttributes(string $attrString): array
    {
        return self::parseMarkupAttributes($attrString);
    }

    public static function appendChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): ObjectEntry
    {
        // php-src: DocumentFragment is never a valid Attr child (even when it only holds text; #24512).
        if (self::isDocumentFragment($child)) {
            if (self::isAttr($parent)) {
                DomExceptionConstants::raiseHierarchyRequest();
            }

            return self::appendFragmentChildren($ctx, $parent, $child);
        }
        // php-src ext/dom/node.c: Attr under Element installs via attribute map (not childNodes).
        if (self::isAttr($child)) {
            return self::appendOrInsertAttribute($ctx, $parent, $child);
        }

        // php-src ext/dom/node.c — WRONG_DOCUMENT_ERR before hierarchy type rejection (#30271).
        self::assertCanReceiveTreeMutationChild($parent, $child);

        $parentState = DomRegistry::state($parent);
        if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
            self::assertNotAncestorOfParent($parent, $child);
            self::appendDocumentChild($ctx, $parent, $child);
            self::syncSubtree($ctx, $parent);
            self::registerSubtreeElementIdsIfConnected($child);

            return $child;
        }

        // Legacy DOM: Attr parents accept only Text + EntityReference (#24512 / php-src node.c).
        if (DomConstants::XML_ATTRIBUTE_NODE === $parentState->nodeType) {
            return self::appendChildToAttribute($ctx, $parent, $child);
        }

        if (DomConstants::XML_ELEMENT_NODE !== $parentState->nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $parentState->nodeType
        ) {
            DomExceptionConstants::raiseHierarchyRequest();
        }

        self::assertNotAncestorOfParent($parent, $child);
        self::detachNodeIfAttached($ctx, $child);
        $parentState->childIds[] = $child->id;
        self::linkChildToParent($child, $parent);
        self::syncSubtree($ctx, $parent);
        self::registerSubtreeElementIdsIfConnected($child);

        return $child;
    }

    /** JIT/AOT bridge return — mirrors createElement() Variable wrapping (#17130). */
    public static function appendChildVariable(Context $ctx, ObjectEntry $parent, ObjectEntry $child): Variable
    {
        $entry = self::appendChild($ctx, $parent, $child);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function replaceChild(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ObjectEntry $oldChild
    ): ObjectEntry {
        self::assertMutationParent($parent);
        self::assertChildOfParent($parent, $oldChild, 'DOMNode::replaceChild()');
        // php-src ext/dom/node.c dom_node_replace_child — replacing a node with itself is a no-op (#22678).
        // Without this guard, detachNodeIfAttached() removes oldChild then childIndex() raises NOT_FOUND.
        if ($newChild->id === $oldChild->id) {
            return $oldChild;
        }
        // php-src ext/dom/node.c dom_node_replace_child — DocumentFragment expands in place (#21976).
        if (self::isDocumentFragment($newChild)) {
            if (self::isAttr($parent)) {
                DomExceptionConstants::raiseHierarchyRequest();
            }
            $parentState = DomRegistry::state($parent);
            $index = self::childIndex($parentState->childIds, $oldChild->id);
            if (null === $index) {
                DomExceptionConstants::raiseNotFound();
            }
            $refChild = null;
            if (isset($parentState->childIds[$index + 1])) {
                $refChild = DomRegistry::entry($parentState->childIds[$index + 1]);
            }
            // Keep oldChild IDs in the document table (libxml; #25274) — only destroy paths clear.
            $parentState->childIds = \array_values(\array_filter(
                $parentState->childIds,
                static fn (int $id): bool => $id !== $oldChild->id
            ));
            self::linkChildToParent($oldChild, null);
            if (self::isDocument($parent)) {
                $docEl = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
                if (Variable::TYPE_OBJECT === $docEl->type && $docEl->toObject()->id === $oldChild->id) {
                    $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->null();
                    $parentState->documentElementName = null;
                }
            }
            self::insertFragmentChildrenBefore($ctx, $parent, $newChild, $refChild);
            self::syncSubtree($ctx, $parent);

            return $oldChild;
        }
        // php-src ext/dom/node.c — WRONG_DOCUMENT_ERR before hierarchy type rejection (#30271).
        self::assertCanReceiveTreeMutationChild($parent, $newChild);
        self::assertAttrMutationChild($parent, $newChild);
        // Keep oldChild IDs in the document table (libxml; #25274) — only destroy paths clear.
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        $index = self::childIndex($parentState->childIds, $oldChild->id);
        if (null === $index) {
            DomExceptionConstants::raiseNotFound();
        }
        $parentState->childIds[$index] = $newChild->id;
        self::linkChildToParent($oldChild, null);
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent)) {
            $docEl = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            $oldWasDocEl = Variable::TYPE_OBJECT === $docEl->type && $docEl->toObject()->id === $oldChild->id;
            if (self::isElement($newChild)) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            } elseif ($oldWasDocEl) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->null();
                $parentState->documentElementName = null;
            }
            self::propagateDocumentId($newChild, $parent->id);
        }
        self::syncSubtree($ctx, $parent);
        if (self::isAttr($parent)) {
            self::refreshAttrValueFromChildren($ctx, $parent);
        } else {
            self::registerSubtreeElementIdsIfConnected($newChild);
        }

        return $oldChild;
    }

    public static function insertBefore(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ?ObjectEntry $refChild
    ): ObjectEntry {
        self::assertMutationParent($parent);
        if (self::isDocumentFragment($newChild)) {
            if (self::isAttr($parent)) {
                DomExceptionConstants::raiseHierarchyRequest();
            }

            return self::insertFragmentChildrenBefore($ctx, $parent, $newChild, $refChild);
        }
        // php-src: Attr + null refChild ≡ appendChild(Attr); Attr cannot be a previous sibling of a child node.
        if (self::isAttr($newChild)) {
            if (null !== $refChild) {
                // php-src / libxml: Error (not DOMException) when Attr is inserted as sibling of a child.
                throw new \Error('Cannot add newnode as the previous sibling of refnode');
            }

            return self::appendOrInsertAttribute($ctx, $parent, $newChild);
        }
        // php-src ext/dom/node.c — WRONG_DOCUMENT_ERR before hierarchy type rejection (#30271).
        self::assertCanReceiveTreeMutationChild($parent, $newChild);
        self::assertAttrMutationChild($parent, $newChild);
        if (null !== $refChild) {
            self::assertChildOfParent($parent, $refChild, 'DOMNode::insertBefore()');
            // php-src ext/dom/node.c — xmlAddPrevSibling fails when new == ref → Error, not NOT_FOUND (#22686).
            // Without this guard, detachNodeIfAttached() removes the child then childIndex() raises NOT_FOUND.
            if ($newChild->id === $refChild->id) {
                throw new \Error('Cannot add newnode as the previous sibling of refnode');
            }
        }
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        if (null === $refChild) {
            $parentState->childIds[] = $newChild->id;
        } else {
            $index = self::childIndex($parentState->childIds, $refChild->id);
            if (null === $index) {
                DomExceptionConstants::raiseNotFound();
            }
            \array_splice($parentState->childIds, $index, 0, [$newChild->id]);
        }
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent)) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL === $existing->type && self::isElement($newChild)) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            }
            self::propagateDocumentId($newChild, $parent->id);
        }
        self::syncSubtree($ctx, $parent);
        if (self::isAttr($parent)) {
            self::refreshAttrValueFromChildren($ctx, $parent);
        } else {
            self::registerSubtreeElementIdsIfConnected($newChild);
        }

        return $newChild;
    }

    public static function removeChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): ObjectEntry
    {
        self::assertMutationParent($parent);
        self::assertChildOfParent($parent, $child, 'DOMNode::removeChild()');
        // libxml keeps ID table entries on unlink; getElementById filters detached (#25274).
        $parentState = DomRegistry::state($parent);
        $parentState->childIds = \array_values(\array_filter(
            $parentState->childIds,
            static fn (int $id): bool => $id !== $child->id
        ));
        self::linkChildToParent($child, null);
        if (self::isDocument($parent)) {
            $docEl = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT === $docEl->type && $docEl->toObject()->id === $child->id) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->null();
                $parentState->documentElementName = null;
            }
        }
        self::syncSubtree($ctx, $parent);
        if (self::isAttr($parent)) {
            self::refreshAttrValueFromChildren($ctx, $parent);
        }

        return $child;
    }

    private static function appendFragmentChildren(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $fragment
    ): ObjectEntry {
        return self::insertFragmentChildrenBefore($ctx, $parent, $fragment, null);
    }

    private static function insertFragmentChildrenBefore(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $fragment,
        ?ObjectEntry $refChild
    ): ObjectEntry {
        if (!self::isDocumentFragment($fragment)) {
            throw new \LogicException('insertFragmentChildrenBefore() expects a DOMDocumentFragment');
        }

        $fragState = DomRegistry::state($fragment);
        $childIds = $fragState->childIds;
        $fragState->childIds = [];
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            self::linkChildToParent($child, null);
            if (null === $refChild) {
                self::appendChild($ctx, $parent, $child);
            } else {
                self::insertBeforeLiveStandard($ctx, $parent, $child, $refChild);
            }
        }
        self::syncSubtree($ctx, $fragment);
        self::syncSubtree($ctx, $parent);

        return $fragment;
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function appendLiveStandardNodes(Context $ctx, ObjectEntry $parent, array $args): void
    {
        self::assertMutationParent($parent);
        foreach ($args as $arg) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $arg, 'DOMNode::append()');
            self::appendLiveStandardChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $parent);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function replaceChildrenLiveStandardNodes(Context $ctx, ObjectEntry $parent, array $args): void
    {
        self::assertMutationParent($parent);
        self::removeAllLiveStandardChildren($ctx, $parent);
        foreach ($args as $arg) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $arg, 'DOMNode::replaceChildren()');
            self::appendLiveStandardChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $parent);
    }

    public static function replaceChildrenLiveStandardObjects(Context $ctx, ObjectEntry $parent, ObjectEntry ...$children): void
    {
        self::assertMutationParent($parent);
        self::removeAllLiveStandardChildren($ctx, $parent);
        foreach ($children as $child) {
            self::appendLiveStandardChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $parent);
    }

    private static function removeAllLiveStandardChildren(
        Context $ctx,
        ObjectEntry $parent,
        bool $freeRemovedChildList = false
    ): void
    {
        $parentState = DomRegistry::state($parent);
        $existingIds = $parentState->childIds;
        // Unregister IDs while children are still connected (php-src textContent / replaceChildren).
        foreach ($existingIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::unregisterSubtreeElementIdsIfConnected($child);
            }
        }
        $parentState->childIds = [];
        if (self::isDocument($parent)) {
            $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->null();
            $parentState->documentElementName = null;
        }
        foreach ($existingIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::linkChildToParent($child, null);
            }
        }
        if ($freeRemovedChildList) {
            self::freeRemovedChildrenLikeLibxml($existingIds);
        }
    }

    /**
     * php-src dom_remove_all_children + php_libxml_node_free_list (#23817).
     *
     * Walk removed children: free unheld nodes; when the first user-held child is kept,
     * invalidate its following siblings (Zend fatals on their property access).
     *
     * @param list<int> $existingIds
     */
    private static function freeRemovedChildrenLikeLibxml(array $existingIds): void
    {
        if ([] === $existingIds) {
            return;
        }
        $retainedIndex = null;
        foreach ($existingIds as $index => $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !DomRegistry::has($child)) {
                continue;
            }
            if (DomRegistry::state($child)->userHandleCount > 0) {
                $retainedIndex = $index;
                break;
            }
            self::markNodeFreedRecursive($child);
        }
        if (null === $retainedIndex) {
            return;
        }
        $count = \count($existingIds);
        for ($i = $retainedIndex + 1; $i < $count; ++$i) {
            $sibling = DomRegistry::entry($existingIds[$i]);
            if (null !== $sibling) {
                self::markNodeFreedRecursive($sibling);
            }
        }
    }

    private static function markNodeFreedRecursive(ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        if ($state->nodeFreed) {
            return;
        }
        $state->nodeFreed = true;
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::markNodeFreedRecursive($child);
            }
        }
    }

    /** php-src dom_objects_not_found — stale wrapper after textContent child free (#23817). */
    public static function fetchableNodeErrorMessage(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        if (DomRegistry::state($node)->nodeFreed) {
            return 'Couldn\'t fetch '.$node->class->name.'. Node no longer exists';
        }

        return null;
    }

    public static function ensureFetchableNode(ObjectEntry $node): void
    {
        $message = self::fetchableNodeErrorMessage($node);
        if (null !== $message) {
            throw new \Error($message);
        }
    }

    /** Track user CV/global assignment for php_libxml_node_free_list simulation (#23817). */
    public static function retainUserHandleFromVariable(Variable $var): void
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return;
        }
        try {
            self::retainUserHandle(VariableObject::entry($resolved));
        } catch (\TypeError) {
        }
    }

    public static function retainUserHandle(ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        ++DomRegistry::state($node)->userHandleCount;
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function prependLiveStandardNodes(Context $ctx, ObjectEntry $parent, array $args): void
    {
        self::assertMutationParent($parent);
        for ($i = \count($args) - 1; $i >= 0; --$i) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $args[$i], 'DOMNode::prepend()');
            self::prependLiveStandardChild($ctx, $parent, $child);
        }
        self::syncSubtree($ctx, $parent);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function beforeLiveStandardNodes(Context $ctx, ObjectEntry $node, array $args): void
    {
        $parent = self::parentEntryForSiblingMutation($node);
        foreach ($args as $arg) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $arg, 'DOMNode::before()');
            self::insertBeforeSibling($ctx, $parent, $child, $node);
        }
        self::syncSubtree($ctx, $parent);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function afterLiveStandardNodes(Context $ctx, ObjectEntry $node, array $args): void
    {
        $parent = self::parentEntryForSiblingMutation($node);
        $anchor = $node;
        foreach ($args as $arg) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $arg, 'DOMNode::after()');
            self::insertAfterSibling($ctx, $parent, $child, $anchor);
            if (!self::isDocumentFragment($child)) {
                $anchor = $child;
            }
        }
        self::syncSubtree($ctx, $parent);
    }

    /**
     * DOMElement::insertAdjacentHTML() — parse HTML and insert by position (php-src PHP-8.5+ php_dom.stub.php; #26063).
     */
    public static function insertAdjacentHTML(
        Context $ctx,
        ObjectEntry $element,
        string $position,
        string $html
    ): void {
        if (!self::isElement($element)) {
            throw new \LogicException('insertAdjacentHTML() expects a DOMElement in this compiler build');
        }
        $pos = strtolower($position);
        if (!\in_array($pos, ['beforebegin', 'afterbegin', 'beforeend', 'afterend'], true)) {
            throw new \ValueError(
                'DOMElement::insertAdjacentHTML(): Argument #1 ($position) must be a valid adjacency insertion position'
            );
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        if (null === $ownerDocument) {
            throw new \DOMException('Hierarchy request error');
        }
        $fragment = self::parseHtmlIntoFragment($ctx, $html, $ownerDocument);
        match ($pos) {
            'beforebegin' => self::insertAdjacentHtmlBeforeBegin($ctx, $element, $fragment),
            'afterbegin' => self::insertAdjacentHtmlAfterBegin($ctx, $element, $fragment),
            'beforeend' => self::insertAdjacentHtmlBeforeEnd($ctx, $element, $fragment),
            'afterend' => self::insertAdjacentHtmlAfterEnd($ctx, $element, $fragment),
        };
    }

    /**
     * DOMElement::insertAdjacentElement() — insert element by position (php-src ext/dom/php_dom.c; #16865).
     */
    public static function insertAdjacentElement(
        Context $ctx,
        ObjectEntry $element,
        string $position,
        ?ObjectEntry $nodeElement
    ): ?ObjectEntry {
        if (null === $nodeElement) {
            return null;
        }
        if (!self::isElement($element)) {
            throw new \LogicException('insertAdjacentElement() expects a DOMElement in this compiler build');
        }
        if (!self::isElement($nodeElement)) {
            throw new \TypeError(
                'DOMElement::insertAdjacentElement(): Argument #2 ($element) must be of type ?DOMElement, '
                .$nodeElement->class->name.' given'
            );
        }
        $pos = strtolower($position);
        if (!\in_array($pos, ['beforebegin', 'afterbegin', 'beforeend', 'afterend'], true)) {
            throw new \ValueError(
                'DOMElement::insertAdjacentElement(): Argument #1 ($where) must be a valid adjacency insertion position'
            );
        }
        match ($pos) {
            'beforebegin' => self::insertAdjacentElementBeforeBegin($ctx, $element, $nodeElement),
            'afterbegin' => self::insertAdjacentElementAfterBegin($ctx, $element, $nodeElement),
            'beforeend' => self::insertAdjacentElementBeforeEnd($ctx, $element, $nodeElement),
            'afterend' => self::insertAdjacentElementAfterEnd($ctx, $element, $nodeElement),
        };

        return $nodeElement;
    }

    /**
     * DOMElement::insertAdjacentText() — insert text node by position (php-src ext/dom/element.c; #16914).
     */
    public static function insertAdjacentText(
        Context $ctx,
        ObjectEntry $element,
        string $position,
        string $data
    ): void {
        if (!self::isElement($element)) {
            throw new \LogicException('insertAdjacentText() expects a DOMElement in this compiler build');
        }
        $pos = strtolower($position);
        if (!\in_array($pos, ['beforebegin', 'afterbegin', 'beforeend', 'afterend'], true)) {
            throw new \ValueError(
                'DOMElement::insertAdjacentText(): Argument #1 ($where) must be a valid adjacency insertion position'
            );
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        $textNode = self::createTextNode($ctx, $data, $ownerDocument);
        match ($pos) {
            'beforebegin' => self::insertAdjacentElementBeforeBegin($ctx, $element, $textNode),
            'afterbegin' => self::insertAdjacentElementAfterBegin($ctx, $element, $textNode),
            'beforeend' => self::insertAdjacentElementBeforeEnd($ctx, $element, $textNode),
            'afterend' => self::insertAdjacentElementAfterEnd($ctx, $element, $textNode),
        };
    }

    private static function insertAdjacentElementBeforeBegin(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $nodeElement
    ): void {
        $parent = self::parentEntryForSiblingMutation($element);
        self::insertBeforeSibling($ctx, $parent, $nodeElement, $element);
        self::syncSubtree($ctx, $parent);
    }

    private static function insertAdjacentElementAfterBegin(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $nodeElement
    ): void {
        self::prependLiveStandardChild($ctx, $element, $nodeElement);
        self::syncSubtree($ctx, $element);
    }

    private static function insertAdjacentElementBeforeEnd(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $nodeElement
    ): void {
        self::appendLiveStandardChild($ctx, $element, $nodeElement);
        self::syncSubtree($ctx, $element);
    }

    private static function insertAdjacentElementAfterEnd(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $nodeElement
    ): void {
        $parent = self::parentEntryForSiblingMutation($element);
        self::insertAfterSibling($ctx, $parent, $nodeElement, $element);
        self::syncSubtree($ctx, $parent);
    }

    private static function insertAdjacentHtmlBeforeBegin(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $fragment
    ): void {
        $parent = self::parentEntryForSiblingMutation($element);
        self::insertBeforeSibling($ctx, $parent, $fragment, $element);
        self::syncSubtree($ctx, $parent);
    }

    private static function insertAdjacentHtmlAfterBegin(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $fragment
    ): void {
        self::prependLiveStandardChild($ctx, $element, $fragment);
        self::syncSubtree($ctx, $element);
    }

    private static function insertAdjacentHtmlBeforeEnd(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $fragment
    ): void {
        self::appendLiveStandardChild($ctx, $element, $fragment);
        self::syncSubtree($ctx, $element);
    }

    private static function insertAdjacentHtmlAfterEnd(
        Context $ctx,
        ObjectEntry $element,
        ObjectEntry $fragment
    ): void {
        $parent = self::parentEntryForSiblingMutation($element);
        self::insertAfterSibling($ctx, $parent, $fragment, $element);
        self::syncSubtree($ctx, $parent);
    }

    private static function parseHtmlIntoFragment(
        Context $ctx,
        string $html,
        ObjectEntry $ownerDocument
    ): ObjectEntry {
        $fragment = self::createDocumentFragment($ctx, $ownerDocument)->toObject();
        if ('' === $html) {
            return $fragment;
        }
        $wrapper = self::createElement($ctx, 'div')->toObject();
        self::appendHtmlChildren($ctx, $wrapper, $html, $ownerDocument);
        $wrapperState = DomRegistry::state($wrapper);
        $fragState = DomRegistry::state($fragment);
        foreach ($wrapperState->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            self::linkChildToParent($child, null);
            $fragState->childIds[] = $childId;
            self::propagateDocumentId($child, $ownerDocument->id);
        }
        $wrapperState->childIds = [];

        return $fragment;
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $args
     */
    public static function replaceWithLiveStandardNodes(Context $ctx, ObjectEntry $node, array $args): void
    {
        $parent = self::parentEntryForSiblingMutation($node);
        foreach ($args as $arg) {
            $child = self::resolveLiveStandardAppendArg($ctx, $parent, $arg, 'DOMNode::replaceWith()');
            self::insertBeforeSibling($ctx, $parent, $child, $node);
        }
        self::removeLiveStandard($ctx, $node);
        self::syncSubtree($ctx, $parent);
    }

    public static function removeLiveStandard(Context $ctx, ObjectEntry $node): void
    {
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            DomExceptionConstants::raiseNotFound();
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            DomExceptionConstants::raiseNotFound();
        }
        self::removeChild($ctx, $parent, $node);
    }

    /**
     * DOMNode::normalize() — merge adjacent text nodes, drop empty text (php-src ext/dom/node.c; #14395).
     */
    public static function normalizeLiveStandard(Context $ctx, ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::normalizeLiveStandard($ctx, $child);
            }
        }
        if (!self::nodeSupportsChildList($node)) {
            return;
        }
        $mergedChildIds = [];
        $carryTextId = null;
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (!self::isTextNode($child)) {
                $carryTextId = null;
                $mergedChildIds[] = $childId;

                continue;
            }
            $textState = DomRegistry::state($child);
            $text = $textState->textContent ?? '';
            if ('' === $text) {
                self::linkChildToParent($child, null);

                continue;
            }
            if (null !== $carryTextId) {
                $carry = DomRegistry::entry($carryTextId);
                if (null !== $carry) {
                    $carryState = DomRegistry::state($carry);
                    $combined = ($carryState->textContent ?? '').$text;
                    self::setTextNodeData($carry, $combined);
                    self::linkChildToParent($child, null);
                }

                continue;
            }
            $carryTextId = $childId;
            $mergedChildIds[] = $childId;
        }
        $state->childIds = $mergedChildIds;
        self::syncSubtree($ctx, $node);
    }

    private static function nodeSupportsChildList(ObjectEntry $node): bool
    {
        if (!DomRegistry::has($node)) {
            return false;
        }
        $type = DomRegistry::state($node)->nodeType;

        return DomConstants::XML_ELEMENT_NODE === $type
            || DomConstants::XML_DOCUMENT_NODE === $type
            || DomConstants::XML_DOCUMENT_FRAG_NODE === $type;
    }

    private static function setTextNodeData(ObjectEntry $textNode, string $data): void
    {
        $state = DomRegistry::state($textNode);
        $state->textContent = $data;
        if ($textNode->hasProperty(self::PROP_NODE_VALUE)) {
            $textNode->getProperty(self::PROP_NODE_VALUE)->string($data);
        }
        if ($textNode->hasProperty(self::PROP_TEXT_CONTENT)) {
            $textNode->getProperty(self::PROP_TEXT_CONTENT)->string($data);
        }
    }

    private static function parentEntryForSiblingMutation(ObjectEntry $node): ObjectEntry
    {
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            throw new \DOMException('Hierarchy request error');
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            throw new \DOMException('Hierarchy request error');
        }

        return $parent;
    }

    private static function insertBeforeSibling(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ObjectEntry $refNode
    ): void {
        if (self::isDocumentFragment($newChild)) {
            $fragState = DomRegistry::state($newChild);
            $childIds = $fragState->childIds;
            $fragState->childIds = [];
            foreach ($childIds as $childId) {
                $fragChild = DomRegistry::entry($childId);
                if (null === $fragChild) {
                    continue;
                }
                self::linkChildToParent($fragChild, null);
                self::insertBeforeSibling($ctx, $parent, $fragChild, $refNode);
            }
            self::syncSubtree($ctx, $newChild);

            return;
        }
        self::insertBeforeLiveStandard($ctx, $parent, $newChild, $refNode);
    }

    private static function insertAfterSibling(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ObjectEntry $refNode
    ): void {
        if (self::isDocumentFragment($newChild)) {
            $fragState = DomRegistry::state($newChild);
            $childIds = $fragState->childIds;
            $fragState->childIds = [];
            $anchor = $refNode;
            foreach ($childIds as $childId) {
                $fragChild = DomRegistry::entry($childId);
                if (null === $fragChild) {
                    continue;
                }
                self::linkChildToParent($fragChild, null);
                self::insertAfterSibling($ctx, $parent, $fragChild, $anchor);
                $anchor = $fragChild;
            }
            self::syncSubtree($ctx, $newChild);

            return;
        }
        $parentState = DomRegistry::state($parent);
        $index = self::childIndex($parentState->childIds, $refNode->id);
        if (null === $index) {
            DomExceptionConstants::raiseNotFound();
        }
        $nextIndex = $index + 1;
        $refChild = isset($parentState->childIds[$nextIndex])
            ? DomRegistry::entry($parentState->childIds[$nextIndex])
            : null;
        self::insertBeforeLiveStandard($ctx, $parent, $newChild, $refChild);
    }

    public static function appendLiveStandardChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): void
    {
        if (self::isDocumentFragment($child)) {
            self::appendFragmentChildren($ctx, $parent, $child);

            return;
        }
        if (!self::isElement($child) && !self::isTextOrCdataNode($child) && !self::isEntityReference($child)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $child);
        self::assertNotAncestorOfParent($parent, $child);
        self::detachNodeIfAttached($ctx, $child);

        $parentState = DomRegistry::state($parent);
        if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL === $existing->type && self::isElement($child)) {
                $parentState->childIds = [$child->id];
                $parentState->documentElementName = DomRegistry::state($child)->nodeName;
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($child);
                self::linkChildToParent($child, $parent);
                self::propagateDocumentId($child, $parent->id);
                self::registerSubtreeElementIdsIfConnected($child);

                return;
            }
            $parentState->childIds[] = $child->id;
            self::linkChildToParent($child, $parent);
            if (self::isElement($child)) {
                self::propagateDocumentId($child, $parent->id);
            }
            self::registerSubtreeElementIdsIfConnected($child);

            return;
        }

        if (DomConstants::XML_ELEMENT_NODE !== $parentState->nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $parentState->nodeType
        ) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState->childIds[] = $child->id;
        self::linkChildToParent($child, $parent);
        self::registerSubtreeElementIdsIfConnected($child);
    }

    public static function prependLiveStandardChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): void
    {
        if (self::isDocumentFragment($child)) {
            $fragState = DomRegistry::state($child);
            $childIds = $fragState->childIds;
            $fragState->childIds = [];
            for ($i = \count($childIds) - 1; $i >= 0; --$i) {
                $fragChild = DomRegistry::entry($childIds[$i]);
                if (null === $fragChild) {
                    continue;
                }
                self::linkChildToParent($fragChild, null);
                self::prependLiveStandardChild($ctx, $parent, $fragChild);
            }
            self::syncSubtree($ctx, $child);

            return;
        }

        $firstChild = null;
        if (DomRegistry::has($parent) && [] !== DomRegistry::state($parent)->childIds) {
            $firstChild = DomRegistry::entry(DomRegistry::state($parent)->childIds[0]);
        }
        self::insertBeforeLiveStandard($ctx, $parent, $child, $firstChild);
    }

    private static function insertBeforeLiveStandard(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ?ObjectEntry $refChild
    ): void {
        self::assertMutationParent($parent);
        if (!self::isTreeMutationChild($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $newChild);
        self::assertNotAncestorOfParent($parent, $newChild);
        if (null !== $refChild) {
            self::assertChildOfParent($parent, $refChild, 'DOMNode::insertBefore()');
            // php-src: same-node insertBefore → Error (not DOMException NOT_FOUND after detach) (#22686).
            if ($newChild->id === $refChild->id) {
                throw new \Error('Cannot add newnode as the previous sibling of refnode');
            }
        }
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        if (null === $refChild) {
            if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
                $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
                if (Variable::TYPE_NULL === $existing->type && self::isElement($newChild)) {
                    $parentState->childIds = [$newChild->id];
                    $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
                    $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                    self::linkChildToParent($newChild, $parent);
                    self::propagateDocumentId($newChild, $parent->id);
                    self::registerSubtreeElementIdsIfConnected($newChild);

                    return;
                }
            }
            $parentState->childIds[] = $newChild->id;
        } else {
            $index = self::childIndex($parentState->childIds, $refChild->id);
            if (null === $index) {
                DomExceptionConstants::raiseNotFound();
            }
            \array_splice($parentState->childIds, $index, 0, [$newChild->id]);
        }
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent) && self::isElement($newChild)) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL === $existing->type) {
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            } elseif (null !== $refChild && Variable::TYPE_OBJECT === $existing->type) {
                $docEl = $existing->toObject();
                if ($docEl->id === $refChild->id) {
                    $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
                    $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
                }
            }
            self::propagateDocumentId($newChild, $parent->id);
        }
        self::registerSubtreeElementIdsIfConnected($newChild);
    }

    private static function resolveLiveStandardAppendArg(
        Context $ctx,
        ObjectEntry $parent,
        Variable $arg,
        string $label
    ): ObjectEntry {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $owner = self::ownerDocumentEntry($parent);
            if (null === $owner && self::isDocument($parent)) {
                $owner = $parent;
            }

            return self::createTextNode($ctx, $arg->toString(), $owner);
        }
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s expects argument to be of type DOMNode|string, %s given',
                $label,
                self::typeLabel($arg)
            ));
        }
        $object = $arg->toObject();
        if (!self::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s expects argument to be of type DOMNode|string, %s given',
                $label,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function saveXML(ObjectEntry $document, ?ObjectEntry $node = null, int $options = 0): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveXML() called on non-document node in this compiler build');
        }

        $formatOutput = self::documentFormatOutput($document);
        $noEmptyTag = 0 !== ($options & \PHPCompiler\ext\libxml\LibxmlConstants::LIBXML_NOEMPTYTAG);

        if (null !== $node) {
            if (!self::isDomNode($node)) {
                throw new \TypeError('DOMDocument::saveXML(): Argument #1 ($node) must be of type DOMNode');
            }

            // Node-scoped dump: redeclare namespaces on the dump root (libxml xmlNodeDump;
            // parent may hold HTML nsDef that is outside the serialized subtree) (#26025).
            return self::serializeNode($node, 0, $formatOutput, $noEmptyTag, true);
        }

        $lines = [self::serializeXmlDeclaration($state)];

        $emittedDoctype = false;
        if (null !== $state->doctypeName) {
            $lines[] = self::serializeDoctype(
                $state->doctypeName,
                $state->doctypePublicId ?? '',
                $state->doctypeSystemId ?? '',
                self::documentDoctypeInternalSubset($state)
            );
            $emittedDoctype = true;
        }

        if ([] !== $state->childIds) {
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null === $child) {
                    continue;
                }
                // Avoid duplicating doctype when both document state and child list carry it (#20556).
                if ($emittedDoctype && self::isDocumentType($child)) {
                    continue;
                }
                $lines[] = self::serializeNode($child, 0, $formatOutput, $noEmptyTag);
            }
        } else {
            $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT === $rootVar->type) {
                $lines[] = self::serializeElement($rootVar->toObject(), 0, $formatOutput, $noEmptyTag);
            } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
                $name = self::escapeName($state->documentElementName);
                $lines[] = $noEmptyTag ? '<'.$name.'></'.$name.'>' : '<'.$name.'/>';
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function documentFormatOutput(ObjectEntry $document): bool
    {
        return self::ensureDomDocumentBoolProperty($document, self::PROP_FORMAT_OUTPUT, false);
    }

    private static function ensureDomDocumentBoolProperty(
        ObjectEntry $document,
        string $propName,
        bool $default
    ): bool {
        if (!$document->hasProperty($propName)) {
            return $default;
        }
        $slot = $document->getProperty($propName);
        $prop = $slot->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $prop->type) {
            $slot->bool($default);

            return $default;
        }
        try {
            return $prop->toBool();
        } catch (\Error) {
            $slot->bool($default);

            return $default;
        }
    }

    public static function loadHTML(
        Context $ctx,
        ObjectEntry $document,
        string $html,
        int $options = 0,
        ?\PHPCompiler\Frame $frame = null,
        bool $deferDocumentSlotSync = false
    ): bool {
        self::ensureDocument($document, $deferDocumentSlotSync);
        self::rejectEmptyLoadSource($html, 'DOMDocument::loadHTML()');

        $trimmed = trim($html);
        $childIds = [];
        // libxml htmlReadMemory: leading <?xml encoding="..."> (HTML PI, often ends with '>' not
        // the XML '?''>' form) is both a document PI child and an encoding hint — strip it from
        // the tree source so following markup is not swallowed (#22022 / php-src ext/dom/document.c).
        $doctypeDecl = null;
        $htmlEncoding = null;
        $encodingEligible = true;
        $cursor = $trimmed;
        while (true) {
            $cursor = ltrim($cursor);
            if ('' === $cursor) {
                break;
            }
            $htmlPi = self::parseHtmlProcessingInstructionAt($cursor, 0);
            if (null !== $htmlPi) {
                if ($encodingEligible && 'xml' === $htmlPi['target']) {
                    $declared = self::extractXmlEncodingDeclaration($htmlPi['data']);
                    if (null !== $declared) {
                        $htmlEncoding = $declared;
                    }
                }
                $encodingEligible = false;
                $pi = self::createHtmlLoadProcessingInstruction(
                    $ctx,
                    $htmlPi['target'],
                    $htmlPi['data'],
                    $document
                );
                $childIds[] = $pi->id;
                self::linkChildToParent($pi, $document);
                self::propagateDocumentId($pi, $document->id);
                $cursor = substr($cursor, $htmlPi['end']);

                continue;
            }
            if (null === $doctypeDecl) {
                $maybeDoctype = self::parseHtmlDoctypeDeclaration($cursor);
                if (null !== $maybeDoctype) {
                    $doctypeDecl = $maybeDoctype;
                    $encodingEligible = false;
                    $cursor = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/is', '', $cursor, 1) ?? $cursor;

                    continue;
                }
            }

            break;
        }

        // libxml htmlReadMemory encoding (#22023 / php-src ext/dom/document.c):
        // XML PI → that charset; UTF-8 BOM → UTF-8; early <meta charset> → that charset;
        // else legacy DOMDocument defaults to ISO-8859-1 (bare UTF-8 bytes → mojibake).
        $isLiving = VmDomLiving::isLivingDocument($document);
        $state = DomRegistry::state($document);
        // Dom\HTML_NO_DEFAULT_NS — omit default XHTML ns while building the tree (#26008).
        $prevHtmlNoDefaultNs = $state->htmlNoDefaultNs;
        $state->htmlNoDefaultNs = $isLiving
            && 0 !== ($options & DomLivingConstants::HTML_NO_DEFAULT_NS);
        $overrideEncoding = $isLiving ? $state->encoding : null;
        $hadBom = false;
        if (str_starts_with($cursor, "\xEF\xBB\xBF")) {
            $hadBom = true;
            $cursor = substr($cursor, 3);
        }
        $metaCharset = self::extractHtmlMetaCharset($cursor);
        $decodeEncoding = null;
        if (null !== $overrideEncoding && '' !== $overrideEncoding) {
            $decodeEncoding = $overrideEncoding;
        } elseif (null !== $htmlEncoding) {
            $decodeEncoding = $htmlEncoding;
        } elseif ($hadBom) {
            $decodeEncoding = 'UTF-8';
        } elseif (null !== $metaCharset && self::isHtmlMetaCharsetEarly($cursor, $metaCharset['offset'])) {
            $decodeEncoding = $metaCharset['charset'];
        } else {
            // Living HTML5 docs default UTF-8; legacy DOMDocument matches libxml Latin-1.
            $decodeEncoding = $isLiving ? 'UTF-8' : 'ISO-8859-1';
        }
        $cursor = self::decodeHtmlLoadBytes($cursor, $decodeEncoding);

        $source = self::normalizeHtmlLoadSource($cursor, $options);
        try {
            $root = self::parseHtmlElementTree($ctx, $source, $document, $frame);
        } finally {
            $state->htmlNoDefaultNs = $prevHtmlNoDefaultNs;
        }
        if (null === $root) {
            return false;
        }

        $state->isHtmlDocument = true;
        $noDefDtd = 0 !== ($options & \PHPCompiler\ext\libxml\LibxmlConstants::LIBXML_HTML_NODEFDTD);
        if (null !== $doctypeDecl) {
            $childIds = array_merge(
                [self::attachDoctypeChild(
                    $ctx,
                    $document,
                    $doctypeDecl['name'],
                    $doctypeDecl['publicId'],
                    $doctypeDecl['systemId']
                )->id],
                $childIds
            );
        } elseif (!$noDefDtd && !$isLiving) {
            // Legacy libxml loadHTML invents HTML 4.0 Transitional when no doctype is present.
            // Dom\HTMLDocument (lexbor / php-src html_document.c) leaves doctype null (#26924).
            $childIds = array_merge(
                [self::attachDoctypeChild(
                    $ctx,
                    $document,
                    'html',
                    '-//W3C//DTD HTML 4.0 Transitional//EN',
                    'http://www.w3.org/TR/REC-html40/loose.dtd'
                )->id],
                $childIds
            );
        }
        $childIds[] = $root->id;
        $state->childIds = $childIds;
        $state->idAttrByElement = [];
        $state->elementIds = [];
        $state->xmlVersion = '1.0';
        // Living Dom\* may set overrideEncoding before loadHTML (#20898).
        // Legacy: meta charset sets DOMDocument::$encoding (even when too late to redecode); else null.
        if (!$isLiving) {
            $state->encoding = null !== $metaCharset ? $metaCharset['charset'] : null;
        }
        $state->xmlStandalone = false;
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        if (!$deferDocumentSlotSync) {
            $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));
        }
        self::linkChildToParent($root, $document);
        self::propagateDocumentId($root, $document->id);
        // Living HTML5 (lexbor): always insert an implied <head> under <html> unless
        // LIBXML_HTML_NOIMPLIED — legacy libxml loadHTML leaves body-only trees (#26023).
        if ($isLiving) {
            self::ensureLivingHtmlImpliedHead($ctx, $document, $root, $options);
        }
        if (!$deferDocumentSlotSync) {
            self::syncSubtree($ctx, $document);
        }
        self::reindexDocumentIds($document, $root);
        if (!$deferDocumentSlotSync) {
            self::syncElementIdMapProperty($document);
        }
        // Living Dom\* docs: unset URL → about:blank (php-src follow_spec; #20898).
        $state->documentUri = VmDomLiving::isLivingDocument($document) ? null : self::defaultDocumentUri();

        return true;
    }

    /**
     * HTML text/attr escape modes for serializeHtmlNode (libxml htmlNodeDump / htmlDocDump).
     *
     * UTF8: &<> only — HTML document node-scoped saveHTML (#24152); also XML docs with
     * a non-empty $encoding (#25246).
     * NAMED: HTML 4.01 named entities — HTML document-wide saveHTML (#23684); also XML
     * docs with a non-empty $encoding (#25246).
     * NUMERIC: &#xHH; hex refs — XML documents with null/empty encoding (#25208).
     */
    private const HTML_ESCAPE_UTF8 = 0;
    private const HTML_ESCAPE_NAMED = 1;
    private const HTML_ESCAPE_NUMERIC = 2;

    /**
     * Whether saveHTML should use the UTF-8 node / named-entity document split.
     *
     * libxml htmlNodeDump/htmlDocDump: when the document has a non-empty encoding
     * (XML decl, DOMDocument ctor, or $doc->encoding=…), non-ASCII stays UTF-8 on
     * node dumps and becomes HTML 4.01 named entities on document dumps. When
     * encoding is null/empty, both dumps use &#xHH; (#25208 / #25246).
     */
    private static function saveHtmlUsesNamedOrUtf8Split(DomNodeState $state): bool
    {
        if ($state->isHtmlDocument) {
            return true;
        }

        return null !== $state->encoding && '' !== $state->encoding;
    }

    public static function saveHTML(ObjectEntry $document, ?ObjectEntry $node = null, int $options = 0): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveHTML() called on non-document node in this compiler build');
        }

        // Encoded / HTML docs: named entities document-wide (#23684 / #25246).
        // Unencoded XML: numeric hex for both dumps (#25208).
        $useSplit = self::saveHtmlUsesNamedOrUtf8Split($state);
        $escapeMode = $useSplit
            ? self::HTML_ESCAPE_NAMED
            : self::HTML_ESCAPE_NUMERIC;

        if (null !== $node) {
            if (!self::isDomNode($node)) {
                throw new \TypeError('DOMDocument::saveHTML(): Argument #1 ($node) must be of type ?DOMNode');
            }

            // libxml htmlNodeDump: never XML self-close; HTML_EMPTY → <br>; else <tag></tag> (#20625).
            // Encoded/HTML: Non-ASCII stays UTF-8 — only &<> escaped (#24152 / #25246).
            // Unencoded XML: numeric hex refs (#25208).
            $nodeMode = $useSplit ? self::HTML_ESCAPE_UTF8 : self::HTML_ESCAPE_NUMERIC;

            return self::serializeHtmlNode($node, false, $nodeMode);
        }

        $lines = [];
        if ([] !== $state->childIds) {
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $lines[] = self::serializeHtmlNode($child, false, $escapeMode);
                }
            }
        } else {
            $lines[] = self::serializeHtmlDoctypeFromDocumentState($state);
            $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT === $rootVar->type) {
                $lines[] = self::serializeHtmlNode($rootVar->toObject(), false, $escapeMode);
            } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
                $name = self::escapeName($state->documentElementName);
                $lines[] = self::formatHtmlEmptyElementDump($name, '');
            }
        }

        return implode('', $lines)."\n";
    }

    public static function saveHTMLFile(ObjectEntry $document, string $filename): int
    {
        $html = self::saveHTML($document);
        $written = file_put_contents($filename, $html);
        if (false === $written) {
            return 0;
        }

        return $written;
    }

    /**
     * DOMDocument::save() — write saveXML() bytes to $filename (php-src ext/dom/php_dom.c; #18435).
     *
     * @return int|false byte count, or false when the file cannot be written
     */
    public static function save(
        ObjectEntry $document,
        string $filename,
        int $options = 0,
        ?Frame $frame = null
    ): int|false {
        unset($options);
        $xml = self::saveXML($document);
        $written = @file_put_contents($filename, $xml);
        if (false === $written) {
            self::triggerDomWarning(
                $frame,
                'DOMDocument::save('.$filename.'): Failed to open stream: No such file or directory'
            );

            return false;
        }

        return $written;
    }

    /**
     * HTML5 "create an element for a token" + "before head" / "after head" — living Dom\\
     * documents always expose a direct <head> child of <html> after parse (php-src lexbor;
     * #26023). Legacy DOMDocument::loadHTML (libxml) does not invent <head>.
     */
    private static function ensureLivingHtmlImpliedHead(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $root,
        int $options
    ): void {
        if (0 !== ($options & \PHPCompiler\ext\libxml\LibxmlConstants::LIBXML_HTML_NOIMPLIED)) {
            return;
        }
        if (!self::isElement($root)) {
            return;
        }
        if ('html' !== strtolower(DomRegistry::state($root)->nodeName)) {
            return;
        }
        if (null !== VmDomLiving::findDirectChildElementByLocalName($root, 'head')) {
            return;
        }
        $headVar = self::createElement($ctx, 'head', $document);
        if (Variable::TYPE_OBJECT !== $headVar->type) {
            return;
        }
        $head = $headVar->toObject();
        $body = VmDomLiving::findDirectChildElementByLocalName($root, 'body');
        self::insertBefore($ctx, $root, $head, $body);
    }

    private static function normalizeHtmlLoadSource(string $html, int $options): string
    {
        $trimmed = trim($html);
        if (0 !== ($options & \PHPCompiler\ext\libxml\LibxmlConstants::LIBXML_HTML_NOIMPLIED)) {
            return $trimmed;
        }
        $pos = 0;
        $len = \strlen($trimmed);
        while ($pos < $len && ctype_space($trimmed[$pos])) {
            ++$pos;
        }
        if ($pos < $len && '<' === $trimmed[$pos]) {
            $rest = substr($trimmed, $pos + 1);
            $restLc = strtolower($rest);
            if (str_starts_with($restLc, '!doctype') || str_starts_with($restLc, 'html')) {
                $close = strpos($trimmed, '>');
                if (false !== $close && str_starts_with(strtolower(ltrim(substr($trimmed, $pos + 1))), '!doctype')) {
                    return ltrim(substr($trimmed, $close + 1));
                }

                return $trimmed;
            }
            // Leading <body>/<head>: wrap in <html> only — avoid nested <body> (#25988).
            // Preg-free (VmPregPure / AOT loadHTML; #17954).
            if (self::htmlLoadSourceStartsWithBodyOrHead($restLc)) {
                return '<html>'.$trimmed.'</html>';
            }
        }

        return '<html><body>'.$trimmed.'</body></html>';
    }

    /** True when $restLc (after '<') is a body/head start tag name boundary. */
    private static function htmlLoadSourceStartsWithBodyOrHead(string $restLc): bool
    {
        foreach (['body', 'head'] as $tag) {
            $n = \strlen($tag);
            if (!str_starts_with($restLc, $tag)) {
                continue;
            }
            if (\strlen($restLc) === $n) {
                return true;
            }
            $next = $restLc[$n];

            return ctype_space($next) || '/' === $next || '>' === $next;
        }

        return false;
    }

    /**
     * HTML processing instruction — closes at '>' (optional '?' before), unlike XML PI close
     * (libxml htmlParse, php-src ext/dom/document.c; #22022).
     *
     * @return null|array{target: string, data: string, end: int}
     */
    private static function parseHtmlProcessingInstructionAt(string $content, int $pos): ?array
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (!isset($content[$pos + 1]) || '?' !== $content[$pos + 1]) {
            return null;
        }
        // Preg-free scan — VmPregPure lacks \G (#17954); HTML PI ends at '>'.
        $len = \strlen($content);
        $i = $pos + 2;
        if ($i >= $len || !self::isHtmlTagNameStart($content[$i])) {
            return null;
        }
        $targetStart = $i;
        ++$i;
        while ($i < $len && self::isHtmlPiTargetChar($content[$i])) {
            ++$i;
        }
        $target = substr($content, $targetStart, $i - $targetStart);
        $dataStart = $i;
        while ($i < $len && '>' !== $content[$i]) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $data = substr($content, $dataStart, $i - $dataStart);
        if (isset($data[0]) && ctype_space($data[0])) {
            $data = ltrim($data);
        }

        return [
            'target' => $target,
            'data' => $data,
            'end' => $i + 1,
        ];
    }

    private static function isHtmlPiTargetChar(string $ch): bool
    {
        return !ctype_space($ch) && '?' !== $ch && '<' !== $ch && '>' !== $ch;
    }

    /** @return null|string encoding name from xml encoding= PI data */
    private static function extractXmlEncodingDeclaration(string $piData): ?string
    {
        // Preg-free (no backrefs) for AOT/VmPregPure (#17954 / #22022).
        $at = stripos($piData, 'encoding');
        if (false === $at) {
            return null;
        }
        $len = \strlen($piData);
        $i = $at + 8; // strlen('encoding')
        while ($i < $len && ctype_space($piData[$i])) {
            ++$i;
        }
        if ($i >= $len || '=' !== $piData[$i]) {
            return null;
        }
        ++$i;
        while ($i < $len && ctype_space($piData[$i])) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $quote = $piData[$i];
        if ('"' !== $quote && "'" !== $quote) {
            return null;
        }
        ++$i;
        $start = $i;
        while ($i < $len && $piData[$i] !== $quote) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $encoding = substr($piData, $start, $i - $start);

        return '' === $encoding ? null : $encoding;
    }

    /**
     * Interpret loadHTML source bytes in $encoding into UTF-8 DOM text (libxml htmlReadMemory).
     */
    private static function decodeHtmlLoadBytes(string $source, string $encoding): string
    {
        $from = CharsetEngine::canonicalize($encoding);
        if (null === $from || 'UTF-8' === $from) {
            return $source;
        }
        $converted = CharsetEngine::convert($from, 'UTF-8', $source);

        return false === $converted ? $source : $converted;
    }

    /**
     * First &lt;meta charset&gt; / http-equiv Content-Type charset in HTML source (#22023).
     *
     * Preg-free for AOT/VmPregPure (#17954).
     *
     * @return null|array{charset: string, offset: int}
     */
    private static function extractHtmlMetaCharset(string $html): ?array
    {
        $len = \strlen($html);
        $offset = 0;
        while ($offset < $len) {
            $lt = strpos($html, '<', $offset);
            if (false === $lt) {
                break;
            }
            if ($lt + 4 <= $len && 0 === substr_compare($html, '<!--', $lt, 4)) {
                $end = strpos($html, '-->', $lt + 4);
                $offset = false === $end ? $len : $end + 3;

                continue;
            }
            if ($lt + 5 <= $len && 0 === substr_compare($html, 'meta', $lt + 1, 4, true)) {
                $boundary = $html[$lt + 5] ?? '';
                if ('' === $boundary || ctype_space($boundary) || '/' === $boundary || '>' === $boundary) {
                    $gt = strpos($html, '>', $lt);
                    if (false === $gt) {
                        break;
                    }
                    $attrs = substr($html, $lt + 5, $gt - ($lt + 5));
                    $charset = self::parseHtmlMetaCharsetAttributes($attrs);
                    if (null !== $charset) {
                        return ['charset' => $charset, 'offset' => $lt];
                    }
                    $offset = $gt + 1;

                    continue;
                }
            }
            $offset = $lt + 1;
        }

        return null;
    }

    /** @return null|string charset token from a &lt;meta …&gt; attribute string */
    private static function parseHtmlMetaCharsetAttributes(string $attrs): ?string
    {
        $charset = self::extractHtmlAttributeValue($attrs, 'charset');
        if (null !== $charset && '' !== $charset) {
            return $charset;
        }
        $httpEquiv = self::extractHtmlAttributeValue($attrs, 'http-equiv');
        if (null === $httpEquiv || 0 !== strcasecmp($httpEquiv, 'content-type')) {
            return null;
        }
        $content = self::extractHtmlAttributeValue($attrs, 'content');
        if (null === $content) {
            return null;
        }
        $at = stripos($content, 'charset');
        if (false === $at) {
            return null;
        }
        $i = $at + 7; // strlen('charset')
        $len = \strlen($content);
        while ($i < $len && ctype_space($content[$i])) {
            ++$i;
        }
        if ($i >= $len || '=' !== $content[$i]) {
            return null;
        }
        ++$i;
        while ($i < $len && ctype_space($content[$i])) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $start = $i;
        while ($i < $len && ';' !== $content[$i] && !ctype_space($content[$i])) {
            ++$i;
        }
        $value = substr($content, $start, $i - $start);

        return '' === $value ? null : $value;
    }

    /** Preg-free HTML attribute value lookup (quoted or bare). */
    private static function extractHtmlAttributeValue(string $attrs, string $name): ?string
    {
        $len = \strlen($attrs);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && (ctype_space($attrs[$i]) || '/' === $attrs[$i])) {
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            $nameStart = $i;
            while ($i < $len && '=' !== $attrs[$i] && !ctype_space($attrs[$i]) && '/' !== $attrs[$i] && '>' !== $attrs[$i]) {
                ++$i;
            }
            $attrName = substr($attrs, $nameStart, $i - $nameStart);
            while ($i < $len && ctype_space($attrs[$i])) {
                ++$i;
            }
            if ($i >= $len || '=' !== $attrs[$i]) {
                if (0 === strcasecmp($attrName, $name)) {
                    return '';
                }

                continue;
            }
            ++$i;
            while ($i < $len && ctype_space($attrs[$i])) {
                ++$i;
            }
            if ($i >= $len) {
                return 0 === strcasecmp($attrName, $name) ? '' : null;
            }
            $quote = $attrs[$i];
            if ('"' === $quote || "'" === $quote) {
                ++$i;
                $valueStart = $i;
                while ($i < $len && $attrs[$i] !== $quote) {
                    ++$i;
                }
                $value = substr($attrs, $valueStart, $i - $valueStart);
                if ($i < $len) {
                    ++$i;
                }
            } else {
                $valueStart = $i;
                while ($i < $len && !ctype_space($attrs[$i]) && '/' !== $attrs[$i] && '>' !== $attrs[$i]) {
                    ++$i;
                }
                $value = substr($attrs, $valueStart, $i - $valueStart);
            }
            if (0 === strcasecmp($attrName, $name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Meta charset is early enough to redecode when only head-context markup precedes it
     * (libxml htmlCheckEncoding / HTML5 prescan; #22023).
     */
    private static function isHtmlMetaCharsetEarly(string $html, int $metaOffset): bool
    {
        $i = 0;
        while ($i < $metaOffset) {
            if (ctype_space($html[$i])) {
                ++$i;

                continue;
            }
            if ('<' !== $html[$i]) {
                return false;
            }
            if ($i + 4 <= $metaOffset && 0 === substr_compare($html, '<!--', $i, 4)) {
                $end = strpos($html, '-->', $i + 4);
                if (false === $end || $end >= $metaOffset) {
                    return true;
                }
                $i = $end + 3;

                continue;
            }
            if ($i + 2 <= $metaOffset && '!' === $html[$i + 1]
                && $i + 9 <= $metaOffset
                && 0 === substr_compare($html, 'DOCTYPE', $i + 2, 7, true)) {
                $gt = strpos($html, '>', $i);
                if (false === $gt || $gt >= $metaOffset) {
                    return true;
                }
                $i = $gt + 1;

                continue;
            }
            $nameStart = $i + 1;
            if ($nameStart < $metaOffset && '/' === $html[$nameStart]) {
                ++$nameStart;
            }
            $nameEnd = $nameStart;
            while ($nameEnd < $metaOffset && self::isHtmlTagNameChar($html[$nameEnd])) {
                ++$nameEnd;
            }
            $name = strtolower(substr($html, $nameStart, $nameEnd - $nameStart));
            if (!self::isHtmlHeadContextTagName($name)) {
                return false;
            }
            $gt = strpos($html, '>', $i);
            if (false === $gt || $gt >= $metaOffset) {
                return true;
            }
            $i = $gt + 1;
        }

        return true;
    }

    private static function isHtmlHeadContextTagName(string $name): bool
    {
        return match ($name) {
            'html', 'head', 'meta', 'title', 'link', 'base', 'style', 'script', 'noscript', 'template' => true,
            default => false,
        };
    }

    /**
     * Create a PI from HTML load (allows target "xml" — libxml emits encoding PIs; #22022).
     */
    private static function createHtmlLoadProcessingInstruction(
        Context $ctx,
        string $target,
        string $data,
        ObjectEntry $ownerDocument
    ): ObjectEntry {
        if (0 !== strcasecmp($target, 'xml')) {
            return self::createProcessingInstruction($ctx, $target, $data, $ownerDocument);
        }
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_PROCESSING_INSTRUCTION);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_PROCESSING_INSTRUCTION_NODE;
        $state->nodeName = $target;
        $state->textContent = $data;
        $state->documentId = $ownerDocument->id;
        DomRegistry::attach($entry, $state);
        $entry->getProperty(self::PROP_NODE_NAME)->string($target);
        $entry->getProperty(self::PROP_NODE_VALUE)->string($data);
        $entry->getProperty(self::PROP_TARGET)->string($target);
        $entry->getProperty(self::PROP_DATA)->string($data);
        self::initNodePropertySlots($entry);

        return $entry;
    }

    /**
     * HTML5 foreign-content namespace for a start tag (php-src html5_parser / lexbor; #26033).
     *
     * In SVG or MathML foreign content, descendants stay in that namespace — including a nested
     * {@code <svg>} under MathML ({@code predefined_namespaces.phpt}).
     * In the HTML namespace (or no parent context), {@code svg}/{@code math} open those namespaces.
     */
    private static function htmlParseNamespaceForTag(string $localName, ?string $parentNamespace): ?string
    {
        if (VmDomLiving::SVG_NS === $parentNamespace || VmDomLiving::MATHML_NS === $parentNamespace) {
            return $parentNamespace;
        }
        if ('svg' === $localName) {
            return VmDomLiving::SVG_NS;
        }
        if ('math' === $localName) {
            return VmDomLiving::MATHML_NS;
        }

        // null = use createElement (HTML_NS or HTML_NO_DEFAULT_NS null).
        return null;
    }

    private static function parseHtmlElementTree(
        Context $ctx,
        string $html,
        ObjectEntry $ownerDocument,
        ?\PHPCompiler\Frame $frame = null,
        ?string $parentNamespace = null
    ): ?ObjectEntry {
        $trimmed = trim($html);
        if ('' === $trimmed) {
            return null;
        }
        $open = self::scanHtmlOpenTagAt($trimmed, 0);
        if (null === $open) {
            return null;
        }
        if ($open['selfClose']) {
            return self::createHtmlElementFromTag(
                $ctx,
                $open['tag'],
                $open['attrs'],
                '',
                $ownerDocument,
                $frame,
                $parentNamespace
            );
        }
        // Avoid PCRE backreferences and \G — VmPregPure lacks them (#17954, compiled loadHTML/AOT).
        $end = self::findHtmlElementEnd($trimmed, 0);
        if (null === $end) {
            if (null === $frame) {
                return null;
            }

            return self::createHtmlElementFromTag(
                $ctx,
                $open['tag'],
                $open['attrs'],
                substr($trimmed, $open['end']),
                $ownerDocument,
                $frame,
                $parentNamespace
            );
        }
        $segment = substr($trimmed, 0, $end);
        $closePos = strrpos($segment, '</');
        $close = false === $closePos ? null : self::scanHtmlCloseTagAt($trimmed, $closePos);
        if (null === $close || strtolower($close['tag']) !== strtolower($open['tag'])) {
            // Optional end tag omitted — element ends at $end (libxml htmlReadMemory; #20247).
            $inner = substr($trimmed, $open['end'], $end - $open['end']);
            $entry = self::createHtmlElementFromTag(
                $ctx,
                $open['tag'],
                $open['attrs'],
                $inner,
                $ownerDocument,
                $frame,
                $parentNamespace
            );
            self::syncSubtree($ctx, $entry);

            return $entry;
        }
        $inner = substr($trimmed, $open['end'], $closePos - $open['end']);
        // libxml HTML_PARSE_NOIMPLIED: markup after the first root element nests under it
        // (php-src ext/dom/html_document.c / htmlReadMemory; #19360).
        if ($end !== \strlen($trimmed)) {
            $inner .= substr($trimmed, $end);
        }

        $entry = self::createHtmlElementFromTag(
            $ctx,
            $open['tag'],
            $open['attrs'],
            $inner,
            $ownerDocument,
            $frame,
            $parentNamespace
        );
        self::syncSubtree($ctx, $entry);

        return $entry;
    }

    private static function createHtmlElementFromTag(
        Context $ctx,
        string $tagName,
        string $attrPart,
        string $inner,
        ObjectEntry $ownerDocument,
        ?\PHPCompiler\Frame $frame = null,
        ?string $parentNamespace = null,
    ): ObjectEntry {
        $localName = strtolower($tagName);
        $foreignNs = self::htmlParseNamespaceForTag($localName, $parentNamespace);
        // Pass owner so living Dom\HTMLDocument nodeClassMap → Dom\HTMLElement (#20418).
        // Foreign SVG/MathML use createElementNS → Dom\Element (php-src dom_get_element_ce; #26033).
        if (null !== $foreignNs) {
            $entry = self::createElementNS($ctx, $foreignNs, $localName, $ownerDocument)->toObject();
        } else {
            $entry = self::createElement($ctx, $localName, $ownerDocument)->toObject();
        }
        $state = DomRegistry::state($entry);
        // createElement installs HTML nsDef (ensure_html_ns); applyParsedAttributes replaces
        // namespaceDeclarations with attribute-sourced xmlns only — keep the element nsDef (#26025).
        $createElementNsDefs = $state->namespaceDeclarations;
        $state->attributes = self::decodeHtmlAttributeMap(self::parseAttributes($attrPart));
        self::applyQualifiedElementNames($state, $localName);
        self::applyParsedAttributes($state, $state->attributes);
        foreach ($createElementNsDefs as $prefix => $uri) {
            if (!\array_key_exists($prefix, $state->namespaceDeclarations)) {
                $state->namespaceDeclarations[$prefix] = $uri;
            }
        }
        // HTML parse assigns XML_ATTRIBUTE_ID to id (libxml htmlReadMemory; #23514).
        if (isset($state->attributes['id'])) {
            $state->attributeIsId['id'] = true;
        }
        // Opaque text elements: do not re-parse `<` as markup until the matching end tag
        // (libxml htmlReadMemory; #29799).
        if (self::htmlElementHasOpaqueTextContent($localName)) {
            self::appendHtmlOpaqueTextChild($ctx, $entry, $inner, $ownerDocument, $localName);
        } else {
            self::appendHtmlChildren($ctx, $entry, $inner, $ownerDocument, $frame);
        }
        // HTML `<template>` keeps parsed descendants in a DocumentFragment, not childIds
        // (php-src html5_parser.c / php_dom_add_templated_content; #26034).
        if (self::isHtmlTemplateElement($entry)) {
            self::adoptChildrenIntoTemplateContent($ctx, $entry);
        }

        return $entry;
    }

    /**
     * HTML elements whose content is opaque text until the matching end tag
     * (libxml htmlReadMemory; script/style RAWTEXT + title/textarea/… RCDATA-like; #29799).
     */
    private static function htmlElementHasOpaqueTextContent(string $tagLc): bool
    {
        return 'script' === $tagLc
            || 'style' === $tagLc
            || 'xmp' === $tagLc
            || 'iframe' === $tagLc
            || 'noscript' === $tagLc
            || 'noembed' === $tagLc
            || 'noframes' === $tagLc
            || 'plaintext' === $tagLc
            || 'title' === $tagLc
            || 'textarea' === $tagLc
            || 'listing' === $tagLc;
    }

    /**
     * Whether opaque HTML text expands character references (libxml htmlReadMemory; #29799).
     *
     * script/style keep entity markup raw; other opaque elements decode (&lt; → <).
     */
    private static function htmlOpaqueTextDecodesEntities(string $tagLc): bool
    {
        return 'script' !== $tagLc && 'style' !== $tagLc;
    }

    /** Single text child for opaque HTML elements (libxml htmlReadMemory; #29799). */
    private static function appendHtmlOpaqueTextChild(
        Context $ctx,
        ObjectEntry $parent,
        string $inner,
        ObjectEntry $ownerDocument,
        string $tagLc,
    ): void {
        if ('' === $inner) {
            return;
        }
        $text = self::htmlOpaqueTextDecodesEntities($tagLc)
            ? self::decodeHtmlCharacterReferences($inner)
            : $inner;
        if ('' === $text) {
            return;
        }
        $state = DomRegistry::state($parent);
        $textNode = self::createTextNode($ctx, $text, $ownerDocument);
        $state->childIds[] = $textNode->id;
        self::linkChildToParent($textNode, $parent);
    }

    private static function appendHtmlChildren(
        Context $ctx,
        ObjectEntry $parent,
        string $inner,
        ObjectEntry $ownerDocument,
        ?\PHPCompiler\Frame $frame = null,
    ): void {
        $state = DomRegistry::state($parent);
        $parentNamespace = self::isElement($parent) ? ($state->namespaceUri ?? null) : null;
        $pos = 0;
        $len = \strlen($inner);
        while ($pos < $len) {
            while ($pos < $len && ctype_space($inner[$pos])) {
                ++$pos;
            }
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $inner[$pos]) {
                $next = strpos($inner, '<', $pos);
                $text = false === $next ? substr($inner, $pos) : substr($inner, $pos, $next - $pos);
                if ('' !== $text) {
                    // libxml htmlReadMemory expands character references into text (php-src html_document.c; #20260).
                    $textNode = self::createTextNode(
                        $ctx,
                        self::decodeHtmlCharacterReferences($text),
                        $ownerDocument
                    );
                    $state->childIds[] = $textNode->id;
                    self::linkChildToParent($textNode, $parent);
                }
                $pos = false === $next ? $len : $next;

                continue;
            }
            $comment = VmXml::parseCommentAt($inner, $pos);
            if (null !== $comment) {
                $commentNode = self::createComment($ctx, $comment['data'], $ownerDocument);
                $state->childIds[] = $commentNode->id;
                self::linkChildToParent($commentNode, $parent);
                $pos = $comment['end'];

                continue;
            }
            // HTML PIs (<?…>) — do not abort the sibling walk (#22022).
            $htmlPi = self::parseHtmlProcessingInstructionAt($inner, $pos);
            if (null !== $htmlPi) {
                $piNode = self::createHtmlLoadProcessingInstruction(
                    $ctx,
                    $htmlPi['target'],
                    $htmlPi['data'],
                    $ownerDocument
                );
                $state->childIds[] = $piNode->id;
                self::linkChildToParent($piNode, $parent);
                $pos = $htmlPi['end'];

                continue;
            }
            $end = self::findHtmlElementEnd($inner, $pos);
            if (null === $end) {
                $recovered = self::recoverHtmlIncompleteStartTag($inner, $pos);
                if (null !== $recovered) {
                    self::reportDomLoadHtmlUnclosedTagWarnings($ctx, $recovered['tag'], $frame);
                    // libxml creates the element anyway (<p><unclosed → <unclosed></unclosed>; #25988).
                    $child = self::createHtmlElementFromTag(
                        $ctx,
                        $recovered['tag'],
                        $recovered['attrs'],
                        '',
                        $ownerDocument,
                        $frame,
                        $parentNamespace
                    );
                    $state->childIds[] = $child->id;
                    self::linkChildToParent($child, $parent);
                    self::resolveElementNamespaceUri($child);
                    $pos = $recovered['end'];

                    continue;
                }

                return;
            }
            $childHtml = substr($inner, $pos, $end - $pos);
            $child = self::parseHtmlElementTree($ctx, $childHtml, $ownerDocument, $frame, $parentNamespace);
            if (null === $child) {
                return;
            }
            $state->childIds[] = $child->id;
            self::linkChildToParent($child, $parent);
            self::resolveElementNamespaceUri($child);
            $pos = $end;
        }
    }

    /** @return null|int byte offset after one HTML element starting at $pos */
    private static function findHtmlElementEnd(string $content, int $pos): ?int
    {
        $open = self::scanHtmlOpenTagAt($content, $pos);
        if (null === $open) {
            return null;
        }
        if ($open['selfClose']) {
            return $open['end'];
        }

        $tag = strtolower($open['tag']);
        // Opaque text: only the matching end tag closes; `<` inside is literal (#29799).
        if (self::htmlElementHasOpaqueTextContent($tag)) {
            return self::findHtmlOpaqueTextElementEnd($content, $open['end'], $tag);
        }
        /** @var list<string> $stack */
        $stack = [$tag];
        $scan = $open['end'];
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if ('<' !== $content[$scan]) {
                ++$scan;

                continue;
            }
            $close = self::scanHtmlCloseTagAt($content, $scan);
            if (null !== $close) {
                $name = strtolower($close['tag']);
                if (end($stack) === $name) {
                    array_pop($stack);
                    $scan = $close['end'];
                    if ([] === $stack) {
                        return $scan;
                    }

                    continue;
                }
                // Ancestor/other close: auto-close intervening opens (libxml htmlReadMemory;
                // optional tags #20247; non-optional e.g. div/span before </body> #25988).
                while ([] !== $stack && end($stack) !== $name) {
                    array_pop($stack);
                    if ([] === $stack) {
                        // Closed the element we were scanning — leave this close tag for the parent.
                        return $scan;
                    }
                }
                if ([] === $stack || end($stack) !== $name) {
                    return null;
                }
                array_pop($stack);
                $scan = $close['end'];
                if ([] === $stack) {
                    return $scan;
                }

                continue;
            }
            $nested = self::scanHtmlOpenTagAt($content, $scan);
            if (null !== $nested) {
                if (!$nested['selfClose']) {
                    $nestedTag = strtolower($nested['tag']);
                    while ([] !== $stack && self::htmlStartTagClosesOpenElement($nestedTag, (string) end($stack))) {
                        array_pop($stack);
                        if ([] === $stack) {
                            // Implied end before a sibling/ancestor start tag (#20247).
                            return $scan;
                        }
                    }
                    $stack[] = $nestedTag;
                }
                $scan = $nested['end'];

                continue;
            }
            ++$scan;
        }

        // EOF: auto-close remaining opens like libxml htmlReadMemory (#20247 optional;
        // #25988 non-optional div/span/…). Well-formed start tags get no libxml warning.
        while ([] !== $stack) {
            array_pop($stack);
        }

        return $len;
    }

    /**
     * End offset for opaque HTML elements — scan for matching close tag only (#29799).
     *
     * @return int byte offset after the closing tag, or strlen($content) if unclosed
     */
    private static function findHtmlOpaqueTextElementEnd(string $content, int $scan, string $tagLc): int
    {
        $len = \strlen($content);
        while ($scan < $len) {
            if ('<' !== $content[$scan]) {
                ++$scan;

                continue;
            }
            $close = self::scanHtmlCloseTagAt($content, $scan);
            if (null !== $close && strtolower($close['tag']) === $tagLc) {
                return $close['end'];
            }
            ++$scan;
        }

        return $len;
    }

    /**
     * HTML elements whose end tag may be omitted (WHATWG optional tags; #20247).
     *
     * @see https://html.spec.whatwg.org/multipage/syntax.html#optional-tags
     */
    private static function htmlElementHasOptionalEndTag(string $tagLc): bool
    {
        return 'html' === $tagLc
            || 'head' === $tagLc
            || 'body' === $tagLc
            || 'p' === $tagLc
            || 'li' === $tagLc
            || 'dt' === $tagLc
            || 'dd' === $tagLc
            || 'rt' === $tagLc
            || 'rp' === $tagLc
            || 'optgroup' === $tagLc
            || 'option' === $tagLc
            || 'colgroup' === $tagLc
            || 'caption' === $tagLc
            || 'thead' === $tagLc
            || 'tbody' === $tagLc
            || 'tfoot' === $tagLc
            || 'tr' === $tagLc
            || 'td' === $tagLc
            || 'th' === $tagLc;
    }

    /**
     * Whether starting $newTag implies closing an open $openTag (optional end tags; #20247).
     */
    private static function htmlStartTagClosesOpenElement(string $newTag, string $openTag): bool
    {
        if ($openTag === $newTag) {
            return 'p' === $openTag
                || 'li' === $openTag
                || 'dt' === $openTag
                || 'dd' === $openTag
                || 'option' === $openTag
                || 'optgroup' === $openTag
                || 'tr' === $openTag
                || 'td' === $openTag
                || 'th' === $openTag
                || 'rt' === $openTag
                || 'rp' === $openTag;
        }
        if ('p' === $openTag) {
            return self::htmlStartTagClosesParagraph($newTag);
        }
        if (('td' === $openTag || 'th' === $openTag) && ('td' === $newTag || 'th' === $newTag)) {
            return true;
        }
        if (('dt' === $openTag || 'dd' === $openTag) && ('dt' === $newTag || 'dd' === $newTag)) {
            return true;
        }
        if (('thead' === $openTag || 'tbody' === $openTag || 'tfoot' === $openTag)
            && ('thead' === $newTag || 'tbody' === $newTag || 'tfoot' === $newTag)) {
            return true;
        }

        return false;
    }

    /** Start tags that close an open &lt;p&gt; (HTML5 "a p element's end tag can be omitted if…"). */
    private static function htmlStartTagClosesParagraph(string $newTag): bool
    {
        return 'address' === $newTag
            || 'article' === $newTag
            || 'aside' === $newTag
            || 'blockquote' === $newTag
            || 'details' === $newTag
            || 'div' === $newTag
            || 'dl' === $newTag
            || 'fieldset' === $newTag
            || 'figcaption' === $newTag
            || 'figure' === $newTag
            || 'footer' === $newTag
            || 'form' === $newTag
            || 'h1' === $newTag
            || 'h2' === $newTag
            || 'h3' === $newTag
            || 'h4' === $newTag
            || 'h5' === $newTag
            || 'h6' === $newTag
            || 'header' === $newTag
            || 'hgroup' === $newTag
            || 'hr' === $newTag
            || 'main' === $newTag
            || 'menu' === $newTag
            || 'nav' === $newTag
            || 'ol' === $newTag
            || 'p' === $newTag
            || 'pre' === $newTag
            || 'section' === $newTag
            || 'table' === $newTag
            || 'ul' === $newTag;
    }

    /**
     * @return null|array{tag:string, attrs:string, end:int, selfClose:bool}
     */
    private static function scanHtmlOpenTagAt(string $content, int $pos): ?array
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        $len = \strlen($content);
        $i = $pos + 1;
        if ($i >= $len || !self::isHtmlTagNameStart($content[$i])) {
            return null;
        }
        $nameStart = $i;
        ++$i;
        while ($i < $len && self::isHtmlTagNameChar($content[$i])) {
            ++$i;
        }
        $tag = substr($content, $nameStart, $i - $nameStart);
        $attrStart = $i;
        $selfClose = false;
        while ($i < $len) {
            $ch = $content[$i];
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                ++$i;
                while ($i < $len && $content[$i] !== $quote) {
                    ++$i;
                }
                if ($i < $len) {
                    ++$i;
                }

                continue;
            }
            if ('>' === $ch) {
                $tagLc = strtolower($tag);

                return [
                    'tag' => $tag,
                    'attrs' => substr($content, $attrStart, $i - $attrStart),
                    'end' => $i + 1,
                    // HTML5 void elements are implicitly empty (libxml htmlReadMemory; #20199).
                    'selfClose' => self::isHtmlVoidElement($tagLc),
                ];
            }
            if ('/' === $ch && isset($content[$i + 1]) && '>' === $content[$i + 1]) {
                return [
                    'tag' => $tag,
                    'attrs' => substr($content, $attrStart, $i - $attrStart),
                    'end' => $i + 2,
                    'selfClose' => true,
                ];
            }
            ++$i;
        }

        return null;
    }

    /**
     * HTML5 void elements — no end tag; treat as self-closing in the pure-PHP scanner (#20199).
     *
     * @see https://html.spec.whatwg.org/multipage/syntax.html#void-elements
     */
    private static function isHtmlVoidElement(string $tagLc): bool
    {
        return 'area' === $tagLc
            || 'base' === $tagLc
            || 'basefont' === $tagLc
            || 'bgsound' === $tagLc
            || 'br' === $tagLc
            || 'col' === $tagLc
            || 'embed' === $tagLc
            || 'frame' === $tagLc
            || 'hr' === $tagLc
            || 'img' === $tagLc
            || 'input' === $tagLc
            || 'keygen' === $tagLc
            || 'link' === $tagLc
            || 'meta' === $tagLc
            || 'param' === $tagLc
            || 'source' === $tagLc
            || 'track' === $tagLc
            || 'wbr' === $tagLc;
    }

    /**
     * libxml2 HTML_EMPTY tags as dumped by htmlNodeDump / DOMDocument::saveHTML() (#20625).
     *
     * Narrower than HTML5 void used for loadHTML parsing — embed/source/track/wbr expand as
     * <tag></tag> under Zend/libxml HTML serialize.
     */
    private static function isLibxmlHtmlDumpEmptyElement(string $tagLc): bool
    {
        return 'area' === $tagLc
            || 'base' === $tagLc
            || 'basefont' === $tagLc
            || 'br' === $tagLc
            || 'col' === $tagLc
            || 'frame' === $tagLc
            || 'hr' === $tagLc
            || 'img' === $tagLc
            || 'input' === $tagLc
            || 'isindex' === $tagLc
            || 'link' === $tagLc
            || 'meta' === $tagLc
            || 'param' === $tagLc;
    }

    /** Format an empty element for saveHTML dump (php-src htmlNodeDump; #20625). */
    private static function formatHtmlEmptyElementDump(string $escapedName, string $attrPart): string
    {
        if (self::isLibxmlHtmlDumpEmptyElement(strtolower($escapedName))) {
            return '<'.$escapedName.$attrPart.'>';
        }

        return '<'.$escapedName.$attrPart.'></'.$escapedName.'>';
    }

    /** @return null|array{tag:string, end:int} */
    private static function scanHtmlCloseTagAt(string $content, int $pos): ?array
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        $len = \strlen($content);
        $i = $pos + 1;
        if ($i >= $len || '/' !== $content[$i]) {
            return null;
        }
        ++$i;
        if ($i >= $len || !self::isHtmlTagNameStart($content[$i])) {
            return null;
        }
        $nameStart = $i;
        ++$i;
        while ($i < $len && self::isHtmlTagNameChar($content[$i])) {
            ++$i;
        }
        $tag = substr($content, $nameStart, $i - $nameStart);
        while ($i < $len && ctype_space($content[$i])) {
            ++$i;
        }
        if ($i >= $len || '>' !== $content[$i]) {
            return null;
        }

        return ['tag' => $tag, 'end' => $i + 1];
    }

    private static function isHtmlTagNameStart(string $char): bool
    {
        return ctype_alpha($char) || '_' === $char;
    }

    private static function isHtmlTagNameChar(string $char): bool
    {
        return ctype_alnum($char) || '_' === $char || ':' === $char || '.' === $char || '-' === $char;
    }

    /** Innermost unclosed/malformed tag for loadHTML libxml warnings (#16190). */
    private static function detectHtmlUnclosedTagName(string $content, int $pos): ?string
    {
        $recovered = self::recoverHtmlIncompleteStartTag($content, $pos);

        return null === $recovered ? null : $recovered['tag'];
    }

    /**
     * Incomplete HTML start tag (missing '>') — libxml still materializes the element (#16190 / #25988).
     *
     * @return null|array{tag: string, attrs: string, end: int}
     */
    private static function recoverHtmlIncompleteStartTag(string $content, int $pos): ?array
    {
        $tail = substr($content, $pos);
        if ('' === $tail || !isset($tail[0]) || '<' !== $tail[0]) {
            return null;
        }
        // Preg-free scan for AOT/VmPregPure (#17954).
        $len = \strlen($tail);
        if ($len < 2 || !self::isHtmlTagNameStart($tail[1])) {
            return null;
        }
        $i = 2;
        while ($i < $len && self::isHtmlTagNameChar($tail[$i])) {
            ++$i;
        }
        $tag = strtolower(substr($tail, 1, $i - 1));
        $attrStart = $i;
        while ($i < $len && '>' !== $tail[$i] && '<' !== $tail[$i]) {
            ++$i;
        }
        // Complete start tag with '>' is handled by findHtmlElementEnd — not incomplete.
        if ($i < $len && '>' === $tail[$i]) {
            return null;
        }
        $attrs = substr($tail, $attrStart, $i - $attrStart);

        return [
            'tag' => $tag,
            'attrs' => $attrs,
            'end' => $pos + $i,
        ];
    }

    private static function serializeHtmlDoctypeFromDocumentState(DomNodeState $state): string
    {
        if (null === $state->doctypeName) {
            return '';
        }

        return self::formatHtmlDoctype(
            $state->doctypeName,
            $state->doctypePublicId ?? '',
            $state->doctypeSystemId ?? ''
        );
    }

    private static function formatHtmlDoctype(string $name, string $publicId, string $systemId): string
    {
        if ('' === $publicId && '' === $systemId) {
            return '<!DOCTYPE '.self::escapeName($name).'>'."\n";
        }

        return '<!DOCTYPE '.self::escapeName($name)
            .' PUBLIC "'.self::escapeAttr($publicId).'" "'.self::escapeAttr($systemId).'">'."\n";
    }

    private static function serializeHtmlNode(
        ObjectEntry $entry,
        bool $emptySelfClosing = true,
        int $htmlEscapeMode = self::HTML_ESCAPE_NAMED
    ): string {
        if (self::isDocumentType($entry)) {
            $dt = DomRegistry::state($entry);

            return self::formatHtmlDoctype(
                $dt->nodeName,
                $dt->publicId ?? '',
                $dt->systemId ?? ''
            );
        }
        if (self::isProcessingInstruction($entry)) {
            $pi = DomRegistry::state($entry);

            return '<?'.$pi->nodeName.' '.($pi->textContent ?? '').'?>';
        }
        // DocumentFragment: libxml dumps children only (php-src document.c; #22453).
        if (self::isDocumentFragment($entry)) {
            return self::serializeDocumentFragmentChildrenHtml($entry, $emptySelfClosing, $htmlEscapeMode);
        }
        if (self::isElement($entry)) {
            return self::serializeHtmlElement($entry, $emptySelfClosing, $htmlEscapeMode);
        }
        if (self::isTextNode($entry)) {
            // libxml htmlNodeDump: escape &<> in normal text; leave script/style raw (#21149).
            // HTML document dump: non-ASCII → named entities (#23684); node dump keeps UTF-8 (#24152).
            // XML document saveHTML: non-ASCII → &#xHH; (#25208).
            $text = DomRegistry::state($entry)->textContent ?? '';
            if (!self::htmlTextNeedsEntityEscape($entry)) {
                return $text;
            }

            return self::escapeHtmlTextForMode($text, $htmlEscapeMode);
        }
        // libxml htmlNodeDump: CDATA → raw text (no entity escape, no <![CDATA[…]]> wrapper)
        // — php-src ext/dom/document.c / #24580.
        if (self::isCdataNode($entry)) {
            return DomRegistry::state($entry)->textContent ?? '';
        }
        if (self::isCommentNode($entry)) {
            return '<!--'.(DomRegistry::state($entry)->textContent ?? '').'-->';
        }
        if (self::isAttr($entry)) {
            return self::serializeAttributeNodeXml(DomRegistry::state($entry));
        }

        throw new \DOMException('Cannot serialize node type in this compiler build');
    }

    /**
     * saveHTML(DocumentFragment) — concatenate child dumps; empty → '' (libxml; #22453).
     */
    private static function serializeDocumentFragmentChildrenHtml(
        ObjectEntry $fragment,
        bool $emptySelfClosing,
        int $htmlEscapeMode = self::HTML_ESCAPE_NAMED
    ): string {
        $parts = [];
        foreach (DomRegistry::state($fragment)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeHtmlNode($child, $emptySelfClosing, $htmlEscapeMode);
            }
        }

        return implode('', $parts);
    }

    /**
     * Whether HTML text serialization must escape &lt;/&gt;/&amp; (php-src htmlNodeDump; #21149).
     *
     * libxml leaves script/style content unescaped; other elements (including xmp) escape.
     */
    private static function htmlTextNeedsEntityEscape(ObjectEntry $textNode): bool
    {
        $state = DomRegistry::state($textNode);
        if (null === $state->parentId) {
            return true;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent || !self::isElement($parent)) {
            return true;
        }
        $parentState = DomRegistry::state($parent);
        $name = strtolower($parentState->localName ?? $parentState->nodeName);
        $colon = strrpos($name, ':');
        if (false !== $colon) {
            $name = substr($name, $colon + 1);
        }

        return 'script' !== $name && 'style' !== $name;
    }

    private static function serializeHtmlElement(
        ObjectEntry $entry,
        bool $emptySelfClosing = true,
        int $htmlEscapeMode = self::HTML_ESCAPE_NAMED
    ): string {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        // Include nsDef xmlns / xmlns:* like saveXML — libxml htmlNodeDump (#30350).
        // Living Dom\HTMLDocument HTML serialize omits default XHTML xmlns (lexbor; #31304).
        $omitDefaultXhtmlNs = self::elementOwnerIsLivingHtmlDocument($entry);
        $attrPart = self::serializeElementAttributes($entry, false, $htmlEscapeMode, $omitDefaultXhtmlNs);
        $childIds = self::elementSerializationChildIds($entry);
        if ([] === $childIds) {
            if ($emptySelfClosing) {
                // XMLDocument / XML context (#18618, #22773): empty-element form <tag/>.
                return '<'.$name.$attrPart.'/>';
            }

            // HTML document / saveHTML (#20625 / #22773): HTML_EMPTY → <br>; else <tag></tag>.
            return self::formatHtmlEmptyElementDump($name, $attrPart);
        }
        $parts = [];
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeHtmlNode($child, $emptySelfClosing, $htmlEscapeMode);
            }
        }

        return '<'.$name.$attrPart.'>'.implode('', $parts).'</'.$name.'>';
    }

    private static function elementVariable(ObjectEntry $entry): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * @return null|list<ObjectEntry>
     */
    private static function parseFragmentXmlChildren(Context $ctx, string $xml): ?array
    {
        $children = [];
        $pos = 0;
        $len = \strlen($xml);
        while ($pos < $len) {
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $xml[$pos]) {
                $next = strpos($xml, '<', $pos);
                $text = false === $next ? substr($xml, $pos) : substr($xml, $pos, $next - $pos);
                $children[] = self::createTextNode($ctx, $text, null);
                $pos = false === $next ? $len : $next;

                continue;
            }
            $comment = VmXml::parseCommentAt($xml, $pos);
            if (null !== $comment) {
                $children[] = self::createComment($ctx, $comment['data'], null);
                $pos = $comment['end'];

                continue;
            }
            $end = self::findElementEnd($xml, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($xml, $pos, $end - $pos);
            $child = self::parseElementTree($ctx, $childXml, $xml, $pos, []);
            if (null === $child) {
                return null;
            }
            $children[] = $child;
            $pos = $end;
        }

        return $children;
    }

    /**
     * @param array<string, string> $generalEntities
     */
    private static function parseElementTree(
        Context $ctx,
        string $elementXml,
        string $sourceXml,
        int $baseOffset,
        array $generalEntities = [],
        bool $substituteEntities = false,
        ?ObjectEntry $ownerDocument = null
    ): ?ObjectEntry {
        // Offsets are relative to $sourceXml; skip chunk-leading whitespace so lineNo hits the '<' (#20795).
        $chunkLeading = \strlen($elementXml) - \strlen(ltrim($elementXml));
        $baseOffset += $chunkLeading;
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            $entry = self::createElement($ctx, $selfClose[1], $ownerDocument)->toObject();
            $state = DomRegistry::state($entry);
            $state->lineNo = self::lineNoAtOffset($sourceXml, $baseOffset);
            $state->attributes = self::parseAttributes($selfClose[2] ?? '');
            self::applyQualifiedElementNames($state, $selfClose[1]);
            self::applyParsedAttributes($state, $state->attributes);

            return $entry;
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            return null;
        }

        $entry = self::createElement($ctx, $matches[1], $ownerDocument)->toObject();
        $state = DomRegistry::state($entry);
        $state->lineNo = self::lineNoAtOffset($sourceXml, $baseOffset);
        $state->attributes = self::parseAttributes($matches[2] ?? '');
        self::applyQualifiedElementNames($state, $matches[1]);
        self::applyParsedAttributes($state, $state->attributes);
        $openTag = '<'.$matches[1].($matches[2] ?? '').'>';
        $innerBase = $baseOffset + \strlen($openTag);
        $pos = 0;
        $inner = $matches[3];
        $len = \strlen($inner);
        while ($pos < $len) {
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $inner[$pos]) {
                $next = strpos($inner, '<', $pos);
                $text = false === $next ? substr($inner, $pos) : substr($inner, $pos, $next - $pos);
                self::appendParsedTextOrEntityRefs(
                    $ctx,
                    $entry,
                    $text,
                    $ownerDocument,
                    $generalEntities,
                    $substituteEntities,
                    $sourceXml,
                    $innerBase + $pos
                );
                $pos = false === $next ? $len : $next;

                continue;
            }
            $cdata = VmXml::parseCdataSectionAt($inner, $pos);
            if (null !== $cdata) {
                $cdataNode = self::createCdataSection($ctx, $cdata['data'], $ownerDocument);
                self::assignLineNoFromSource($cdataNode, $sourceXml, $innerBase + $pos);
                $state->childIds[] = $cdataNode->id;
                self::linkChildToParent($cdataNode, $entry);
                $pos = $cdata['end'];

                continue;
            }
            $comment = VmXml::parseCommentAt($inner, $pos);
            if (null !== $comment) {
                $commentNode = self::createComment($ctx, $comment['data'], $ownerDocument);
                self::assignLineNoFromSource($commentNode, $sourceXml, $innerBase + $pos);
                $state->childIds[] = $commentNode->id;
                self::linkChildToParent($commentNode, $entry);
                $pos = $comment['end'];

                continue;
            }
            $pi = VmXml::parseProcessingInstructionAt($inner, $pos);
            if (null !== $pi) {
                // Prefer the load document; fall back only when parsing a detached fragment (#20856).
                $owner = $ownerDocument ?? (self::ownerDocumentEntry($entry) ?? $entry);
                $piNode = self::createProcessingInstruction($ctx, $pi['target'], $pi['data'], $owner);
                self::assignLineNoFromSource($piNode, $sourceXml, $innerBase + $pos);
                $state->childIds[] = $piNode->id;
                self::linkChildToParent($piNode, $entry);
                $pos = $pi['end'];

                continue;
            }
            $end = self::findElementEnd($inner, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($inner, $pos, $end - $pos);
            $child = self::parseElementTree(
                $ctx,
                $childXml,
                $sourceXml,
                $innerBase + $pos,
                $generalEntities,
                $substituteEntities,
                $ownerDocument
            );
            if (null === $child) {
                return null;
            }
            $state->childIds[] = $child->id;
            self::linkChildToParent($child, $entry);
            self::resolveElementNamespaceUri($child);
            $pos = $end;
        }

        // Defer syncSubtree until the caller has linked this node into its parent so
        // prefixed Attrs can resolve xmlns from ancestors (php-src/libxml; #20615).
        return $entry;
    }

    private static function applyQualifiedElementNames(DomNodeState $state, string $qualifiedName): void
    {
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
    }

    /** @return null|int byte offset after one element starting at $pos */
    private static function findElementEnd(string $content, int $pos): ?int
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $selfClose, 0, $pos)) {
            return $pos + \strlen($selfClose[0]);
        }
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $open, 0, $pos)) {
            return null;
        }

        /** @var list<string> $stack */
        $stack = [$open[1]];
        $scan = $pos + \strlen($open[0]);
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $content, $close, 0, $scan)) {
                $name = $close[1];
                if ([] === $stack || end($stack) !== $name) {
                    return null;
                }
                array_pop($stack);
                $scan += \strlen($close[0]);
                if ([] === $stack) {
                    return $scan;
                }

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $nested, 0, $scan)) {
                $stack[] = $nested[1];
                $scan += \strlen($nested[0]);

                continue;
            }
            $cdata = VmXml::parseCdataSectionAt($content, $scan);
            if (null !== $cdata) {
                $scan = $cdata['end'];

                continue;
            }
            $comment = VmXml::parseCommentAt($content, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $pi = VmXml::parseProcessingInstructionAt($content, $scan);
            if (null !== $pi) {
                $scan = $pi['end'];

                continue;
            }
            ++$scan;
        }

        return null;
    }

    private static function serializeNode(
        ObjectEntry $entry,
        int $depth = 0,
        bool $format = false,
        bool $noEmptyTag = false,
        bool $redeclarableNsRoot = false
    ): string {
        if (self::isDocumentType($entry)) {
            $dt = DomRegistry::state($entry);

            return self::serializeDoctype(
                $dt->nodeName,
                $dt->publicId ?? '',
                $dt->systemId ?? '',
                $dt->internalSubset
            );
        }
        // DocumentFragment: libxml dumps children only (php-src document.c; #22453).
        if (self::isDocumentFragment($entry)) {
            return self::serializeDocumentFragmentChildrenXml($entry, $format, $noEmptyTag);
        }
        if (self::isElement($entry)) {
            return self::serializeElement($entry, $depth, $format, $noEmptyTag, $redeclarableNsRoot);
        }
        if (self::isTextNode($entry)) {
            $text = self::escapeText(DomRegistry::state($entry)->textContent ?? '');
            if (!$format || '' === $text) {
                return $text;
            }

            return str_repeat('  ', $depth).$text;
        }
        if (self::isCdataNode($entry)) {
            $cdata = '<![CDATA['.(DomRegistry::state($entry)->textContent ?? '').']]>';

            return $format ? str_repeat('  ', $depth).$cdata : $cdata;
        }
        if (self::isCommentNode($entry)) {
            $comment = '<!--'.(DomRegistry::state($entry)->textContent ?? '').'-->';

            return $format ? str_repeat('  ', $depth).$comment : $comment;
        }
        if (self::isProcessingInstruction($entry)) {
            $pi = DomRegistry::state($entry);
            $out = '<?'.$pi->nodeName.' '.($pi->textContent ?? '').'?>';

            return $format ? str_repeat('  ', $depth).$out : $out;
        }
        if (self::isEntityReference($entry)) {
            return '&'.self::escapeName(DomRegistry::state($entry)->nodeName).';';
        }
        // libxml xmlNodeDump XML_ATTRIBUTE_NODE: leading space + name="value" (#32351).
        if (self::isAttr($entry)) {
            $out = self::serializeAttributeNodeXml(DomRegistry::state($entry));

            return $format ? str_repeat('  ', $depth).$out : $out;
        }

        throw new \DOMException('Cannot serialize node type in this compiler build');
    }

    /**
     * xmlNodeDump of XML_ATTRIBUTE_NODE — php-src document.c saveXML (#32351).
     */
    private static function serializeAttributeNodeXml(DomNodeState $state): string
    {
        return ' '.self::escapeName($state->nodeName).'="'.self::escapeAttr($state->textContent ?? '').'"';
    }

    /**
     * saveXML(DocumentFragment) — concatenate child dumps; formatOutput joins with "\n" + trailing
     * newline; empty → '' (libxml xmlNodeDump; #22453).
     */
    private static function serializeDocumentFragmentChildrenXml(
        ObjectEntry $fragment,
        bool $format,
        bool $noEmptyTag
    ): string {
        $parts = [];
        foreach (DomRegistry::state($fragment)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                // Fragment children are dumped at depth 0 (no wrapper indent); each is an ns root.
                $parts[] = self::serializeNode($child, 0, false, $noEmptyTag, true);
            }
        }
        if ([] === $parts) {
            return '';
        }
        if ($format) {
            return implode("\n", $parts)."\n";
        }

        return implode('', $parts);
    }

    /**
     * libxml xmlNodeDump formatOutput: any text/cdata/entity-ref child keeps content inline
     * (php-src ext/dom/document.c → libxml; #21489).
     */
    private static function elementHasInlineFormatContent(ObjectEntry $entry): bool
    {
        foreach (self::elementSerializationChildIds($entry) as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (self::isTextNode($child) || self::isCdataNode($child) || self::isEntityReference($child)) {
                return true;
            }
        }

        return false;
    }

    private static function serializeElement(
        ObjectEntry $entry,
        int $depth = 0,
        bool $format = false,
        bool $noEmptyTag = false,
        bool $redeclarableNsRoot = false
    ): string {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        $attrPart = self::serializeElementAttributes($entry, $redeclarableNsRoot);
        $childIds = self::elementSerializationChildIds($entry);
        if ([] === $childIds) {
            $tag = $noEmptyTag
                ? '<'.$name.$attrPart.'></'.$name.'>'
                : '<'.$name.$attrPart.'/>';

            return $format ? str_repeat('  ', $depth).$tag : $tag;
        }
        // Text/cdata/entity-ref children suppress pretty-print (libxml; #21489).
        if (!$format || self::elementHasInlineFormatContent($entry)) {
            $parts = [];
            foreach ($childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $parts[] = self::serializeNode($child, 0, false, $noEmptyTag);
                }
            }
            $tag = '<'.$name.$attrPart.'>'.implode('', $parts).'</'.$name.'>';

            return $format ? str_repeat('  ', $depth).$tag : $tag;
        }

        $indent = str_repeat('  ', $depth);
        $lines = [$indent.'<'.$name.$attrPart.'>'];
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $lines[] = self::serializeNode($child, $depth + 1, true, $noEmptyTag);
            }
        }
        $lines[] = $indent.'</'.$name.'>';

        return implode("\n", $lines);
    }

    /**
     * Emit attributes plus synthetic xmlns from createElementNS / loadXML nsDef (php-src/libxml; #19397).
     *
     * Shared by saveXML and saveHTML — libxml htmlNodeDump also dumps nsDef (#30350).
     * Living Dom\HTMLDocument HTML dumps omit the default XHTML xmlns (#31304; re-#22773/#26924).
     *
     * @return non-empty-string|''
     */
    private static function serializeElementAttributes(
        ObjectEntry $entry,
        bool $redeclarableNsRoot = false,
        int $htmlEscapeMode = self::HTML_ESCAPE_UTF8,
        bool $omitDefaultXhtmlNs = false
    ): string {
        $state = DomRegistry::state($entry);
        $parts = [];
        $decls = $state->namespaceDeclarations;
        // Ensure the element's own namespaceURI is a dump candidate when nsDef was wiped
        // (HTML parse applyParsedAttributes) or only namespaceUri is set (#26025).
        $ownNs = $state->namespaceUri;
        if (null !== $ownNs && '' !== $ownNs) {
            $ownPrefix = $state->prefix ?? '';
            if (!\array_key_exists($ownPrefix, $decls)) {
                $decls[$ownPrefix] = $ownNs;
            }
        }
        foreach ($decls as $prefix => $uri) {
            $attrName = '' === $prefix ? 'xmlns' : 'xmlns:'.$prefix;
            if (\array_key_exists($attrName, $state->attributes)) {
                continue;
            }
            // Dump roots redeclare even when a non-serialized ancestor already holds the ns (#26025).
            if (!$redeclarableNsRoot && self::parentNamespaceUri($entry, $prefix) === $uri) {
                continue;
            }
            // Dom\HTMLDocument HTML serializer: no default xmlns=XHTML (php-src living; #31304).
            if ($omitDefaultXhtmlNs && '' === $prefix && VmDomLiving::HTML_NS === $uri) {
                continue;
            }
            $parts[] = self::escapeName($attrName).'="'.self::escapeHtmlAttrForMode($uri, $htmlEscapeMode).'"';
        }
        foreach ($state->attributes as $aname => $avalue) {
            $parts[] = self::escapeName($aname).'="'
                .self::serializeAttributeValue($state, $aname, $avalue, $htmlEscapeMode).'"';
        }
        if ([] === $parts) {
            return '';
        }

        return ' '.implode(' ', $parts);
    }

    /**
     * Attr dump value: when Attr has Text/EntityReference children, mirror libxml
     * (entity refs as &name;, text escaped) — php-src saveXML; #24512.
     */
    private static function serializeAttributeValue(
        DomNodeState $elementState,
        string $aname,
        string $avalue,
        int $htmlEscapeMode = self::HTML_ESCAPE_UTF8
    ): string {
        $attrId = $elementState->attributeNodeIds[$aname] ?? null;
        if (null === $attrId) {
            return self::escapeHtmlAttrForMode($avalue, $htmlEscapeMode);
        }
        $attr = DomRegistry::entry($attrId);
        if (null === $attr || !self::isAttr($attr)) {
            return self::escapeHtmlAttrForMode($avalue, $htmlEscapeMode);
        }
        $attrState = DomRegistry::state($attr);
        if ([] === $attrState->childIds) {
            return self::escapeHtmlAttrForMode($avalue, $htmlEscapeMode);
        }
        $parts = [];
        $sawStructured = false;
        foreach ($attrState->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (self::isTextNode($child)) {
                $parts[] = self::escapeHtmlAttrForMode(
                    DomRegistry::state($child)->textContent ?? '',
                    $htmlEscapeMode
                );
            } elseif (self::isEntityReference($child)) {
                $sawStructured = true;
                $parts[] = '&'.self::escapeName(DomRegistry::state($child)->nodeName).';';
            }
        }
        if (!$sawStructured && [] === $parts) {
            return self::escapeHtmlAttrForMode($avalue, $htmlEscapeMode);
        }

        return implode('', $parts);
    }

    /**
     * Matching element object ids in document order (php-src ext/dom/nodelist.c).
     *
     * Document-rooted walks include every matching element under the document.
     * Element-rooted walks are descendants-only (context element excluded), matching
     * DOMElement::getElementsByTagName* (#20377).
     *
     * @return list<int>
     */
    public static function collectElementsByTagName(ObjectEntry $node, string $tagName): array
    {
        $matches = [];
        $want = '*' === $tagName ? null : $tagName;
        if (self::isElement($node)) {
            self::collectElementsByTagNameFromChildren($node, $want, $matches);
        } else {
            self::collectElementsByTagNameRecursive($node, $want, $matches);
        }

        return $matches;
    }

    public static function getElementsByTagName(Context $ctx, ObjectEntry $document, string $tagName): Variable
    {
        self::ensureDocument($document);

        return self::createLiveTagNameNodeList($ctx, $document, $tagName);
    }

    public static function getElementsByTagNameFromNode(
        Context $ctx,
        ObjectEntry $node,
        string $tagName
    ): Variable {
        if (!self::isElement($node)) {
            throw new \DOMException('Not an element node');
        }

        return self::createLiveTagNameNodeList($ctx, $node, $tagName);
    }

    /**
     * Matching element object ids in document order (php-src ext/dom/nodelist.c NS).
     * Element-rooted: descendants only (#20377).
     *
     * @return list<int>
     */
    public static function collectElementsByTagNameNS(
        ObjectEntry $node,
        string $namespaceUri,
        string $localName
    ): array {
        $matches = [];
        if (self::isElement($node)) {
            self::collectElementsByTagNameNSFromChildren($node, $namespaceUri, $localName, $matches);
        } else {
            self::collectElementsByTagNameNSRecursive($node, $namespaceUri, $localName, $matches);
        }

        return $matches;
    }

    public static function getElementsByTagNameNS(
        Context $ctx,
        ObjectEntry $document,
        string $namespaceUri,
        string $localName
    ): Variable {
        self::ensureDocument($document);

        return self::createLiveTagNameNSNodeList($ctx, $document, $namespaceUri, $localName);
    }

    public static function getElementsByTagNameNSFromNode(
        Context $ctx,
        ObjectEntry $node,
        string $namespaceUri,
        string $localName
    ): Variable {
        if (!self::isElement($node)) {
            throw new \DOMException('Not an element node');
        }

        return self::createLiveTagNameNSNodeList($ctx, $node, $namespaceUri, $localName);
    }

    public static function nodeListItem(ObjectEntry $nodeList, int $index): ?ObjectEntry
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::item() called on non-nodelist in this compiler build');
        }
        self::refreshNodeListIfLive($nodeList);

        return self::collectionItem($nodeList, $index);
    }

    public static function nodeListRewind(ObjectEntry $nodeList): void
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::rewind() called on non-nodelist in this compiler build');
        }
        DomRegistry::state($nodeList)->listIterIndex = 0;
    }

    public static function nodeListValid(ObjectEntry $nodeList): bool
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::valid() called on non-nodelist in this compiler build');
        }
        self::refreshNodeListIfLive($nodeList);
        $state = DomRegistry::state($nodeList);

        return $state->listIterIndex < \count($state->listNodeIds);
    }

    public static function nodeListCurrent(ObjectEntry $nodeList): ?ObjectEntry
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::current() called on non-nodelist in this compiler build');
        }
        $state = DomRegistry::state($nodeList);
        if ($state->listIterIndex < 0 || $state->listIterIndex >= \count($state->listNodeIds)) {
            return null;
        }

        return self::nodeListItem($nodeList, $state->listIterIndex);
    }

    public static function nodeListKey(ObjectEntry $nodeList): int
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::key() called on non-nodelist in this compiler build');
        }

        return DomRegistry::state($nodeList)->listIterIndex;
    }

    public static function nodeListCount(ObjectEntry $nodeList): int
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::count() called on non-nodelist in this compiler build');
        }
        self::refreshNodeListIfLive($nodeList);

        return \count(DomRegistry::state($nodeList)->listNodeIds);
    }

    public static function nodeListNext(ObjectEntry $nodeList): void
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::next() called on non-nodelist in this compiler build');
        }
        ++DomRegistry::state($nodeList)->listIterIndex;
    }

    /**
     * DOMNodeList::getIterator() — php-src InternalIterator over live items (#21298, #21466, #21930).
     *
     * Class identity is InternalIterator; membership is live (not a HashTable snapshot) so
     * foreach + removeChild matches Zend skip / stop-on-unlink semantics.
     */
    public static function nodeListGetIterator(Context $ctx, ObjectEntry $nodeList): ObjectEntry
    {
        if (!self::isNodeList($nodeList)) {
            throw new \LogicException('DOMNodeList::getIterator() called on non-nodelist in this compiler build');
        }
        self::refreshNodeListIfLive($nodeList);

        return InternalIteratorBuiltin::fromLiveHandler(
            $ctx,
            DomLiveNodeListIterator::forNodeList($nodeList)
        );
    }

    /**
     * @param list<int> $nodeIds
     */
    public static function createNodeList(Context $ctx, array $nodeIds): Variable
    {
        $class = $ctx->classes[self::CLASS_NODE_LIST] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMNodeList is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NODELIST;
        $state->nodeName = '#nodelist';
        $state->listNodeIds = $nodeIds;
        $state->listIterIndex = 0;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * Dom\HTMLCollection handle — same list state as DOMNodeList (php-src html_collection.c; #20709).
     *
     * @param list<int> $nodeIds
     */
    public static function createHtmlCollection(Context $ctx, array $nodeIds): Variable
    {
        $class = $ctx->classes[VmDomLiving::CLASS_HTML_COLLECTION] ?? null;
        if (null === $class) {
            return self::createNodeList($ctx, $nodeIds);
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NODELIST;
        $state->nodeName = '#htmlcollection';
        $state->listNodeIds = $nodeIds;
        $state->listIterIndex = 0;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * Dom\NodeList handle — XPath node-sets (php-src php_dom.stub.php; #20757).
     *
     * @param list<int> $nodeIds
     */
    public static function createDomNodeList(Context $ctx, array $nodeIds): Variable
    {
        $class = $ctx->classes[VmDomLiving::CLASS_NODE_LIST] ?? null;
        if (null === $class) {
            return self::createNodeList($ctx, $nodeIds);
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NODELIST;
        $state->nodeName = '#nodelist';
        $state->listNodeIds = $nodeIds;
        $state->listIterIndex = 0;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /** Living Dom\XPath query/evaluate node-sets use Dom\NodeList (#20757). */
    public static function prefersDomNodeList(ObjectEntry $xpath): bool
    {
        return VmDomLiving::CLASS_XPATH === strtolower($xpath->class->name);
    }

    /** Living Dom\* receivers use HTMLCollection; legacy DOM* keep DOMNodeList (#20709). */
    public static function prefersHtmlCollection(ObjectEntry $root): bool
    {
        $lc = strtolower($root->class->name);

        return VmDomLiving::isLivingDocument($root)
            || VmDomLiving::isLivingElement($root)
            || VmDomLiving::CLASS_DOCUMENT === $lc;
    }

    /**
     * @param list<int> $nodeIds
     */
    public static function createLiveCollection(Context $ctx, ObjectEntry $root, array $nodeIds): Variable
    {
        if (self::prefersHtmlCollection($root)) {
            return self::createHtmlCollection($ctx, $nodeIds);
        }

        return self::createNodeList($ctx, $nodeIds);
    }

    public static function createLiveTagNameNodeList(
        Context $ctx,
        ObjectEntry $root,
        string $tagName
    ): Variable {
        $var = self::createLiveCollection($ctx, $root, self::collectElementsByTagName($root, $tagName));
        $state = DomRegistry::state($var->toObject());
        $state->listQueryRootId = $root->id;
        $state->listQueryTagName = $tagName;

        return $var;
    }

    public static function createLiveTagNameNSNodeList(
        Context $ctx,
        ObjectEntry $root,
        string $namespaceUri,
        string $localName
    ): Variable {
        $var = self::createLiveCollection(
            $ctx,
            $root,
            self::collectElementsByTagNameNS($root, $namespaceUri, $localName)
        );
        $state = DomRegistry::state($var->toObject());
        $state->listQueryRootId = $root->id;
        $state->listQueryNamespaceUri = $namespaceUri;
        $state->listQueryLocalName = $localName;

        return $var;
    }

    /**
     * Dom\Document::getElementsByClassName() — HTMLCollection by class tokens
     * (php-src ext/dom/parentnode.c / html5; #20556).
     */
    public static function getElementsByClassName(Context $ctx, ObjectEntry $document, string $classNames): Variable
    {
        self::ensureDocument($document);

        return self::createLiveClassNameNodeList($ctx, $document, $classNames);
    }

    public static function getElementsByClassNameFromNode(
        Context $ctx,
        ObjectEntry $node,
        string $classNames
    ): Variable {
        if (!self::isElement($node) && !self::isDocument($node) && !self::isDocumentFragment($node)) {
            throw new \DOMException('Not a ParentNode');
        }

        return self::createLiveClassNameNodeList($ctx, $node, $classNames);
    }

    public static function createLiveClassNameNodeList(
        Context $ctx,
        ObjectEntry $root,
        string $classNames
    ): Variable {
        $var = self::createLiveCollection($ctx, $root, self::collectElementsByClassName($root, $classNames));
        $state = DomRegistry::state($var->toObject());
        $state->listQueryRootId = $root->id;
        $state->listQueryClassNames = $classNames;

        return $var;
    }

    /**
     * Matching element object ids in document order for class-token queries (#20556).
     *
     * @return list<int>
     */
    public static function collectElementsByClassName(ObjectEntry $node, string $classNames): array
    {
        $want = VmDomTokenList::parseTokens($classNames);
        $matches = [];
        if ([] === $want) {
            return $matches;
        }
        if (self::isElement($node)) {
            self::collectElementsByClassNameFromChildren($node, $want, $matches);
        } else {
            self::collectElementsByClassNameRecursive($node, $want, $matches);
        }

        return $matches;
    }

    public static function refreshNodeListIfLive(ObjectEntry $nodeList): void
    {
        if (!self::isNodeList($nodeList)) {
            return;
        }
        $state = DomRegistry::state($nodeList);
        if (null === $state->listQueryRootId) {
            return;
        }
        $root = DomRegistry::entry($state->listQueryRootId);
        if (null === $root) {
            self::updateNodeListMembers($nodeList, []);

            return;
        }
        if (null !== $state->listQueryTagName) {
            $ids = self::collectElementsByTagName($root, $state->listQueryTagName);
        } elseif (null !== $state->listQueryLocalName) {
            $ids = self::collectElementsByTagNameNS(
                $root,
                $state->listQueryNamespaceUri ?? '',
                $state->listQueryLocalName
            );
        } elseif (null !== $state->listQueryClassNames) {
            $ids = self::collectElementsByClassName($root, $state->listQueryClassNames);
        } else {
            return;
        }
        self::updateNodeListMembers($nodeList, $ids);
    }

    public static function isNodeList(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_NODE_LIST !== $lc
            && VmDomLiving::CLASS_HTML_COLLECTION !== $lc
            && VmDomLiving::CLASS_NODE_LIST !== $lc
        ) {
            return false;
        }

        return DomRegistry::has($entry)
            && DomConstants::XML_NODELIST === DomRegistry::state($entry)->nodeType;
    }

    public static function isHtmlCollection(ObjectEntry $entry): bool
    {
        return VmDomLiving::CLASS_HTML_COLLECTION === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_NODELIST === DomRegistry::state($entry)->nodeType;
    }

    /**
     * Dom\HTMLCollection::namedItem() — id or HTML name attr (php-src html_collection.c; #20709).
     */
    public static function htmlCollectionNamedItem(ObjectEntry $collection, string $key): ?ObjectEntry
    {
        if (!self::isHtmlCollection($collection)) {
            throw new \LogicException('Dom\\HTMLCollection::namedItem() called on non-HTMLCollection in this compiler build');
        }
        if ('' === $key) {
            return null;
        }
        self::refreshNodeListIfLive($collection);
        foreach (DomRegistry::state($collection)->listNodeIds as $nodeId) {
            $node = DomRegistry::entry($nodeId);
            if (null === $node || !self::isElement($node)) {
                continue;
            }
            if ((self::getAttribute($node, 'id') ?? '') === $key) {
                return $node;
            }
            if (self::elementIsInHtmlNamespace($node) && (self::getAttribute($node, 'name') ?? '') === $key) {
                return $node;
            }
        }

        return null;
    }

    /** HTML ns or HTMLDocument tree with null ns (our loadHTML path; #20709). */
    private static function elementIsInHtmlNamespace(ObjectEntry $element): bool
    {
        $ns = DomRegistry::state($element)->namespaceUri;
        if (VmDomLiving::HTML_NS === $ns) {
            return true;
        }
        if (null !== $ns && '' !== $ns) {
            return false;
        }
        $owner = self::ownerDocumentEntry($element);
        if (null === $owner) {
            return false;
        }

        return DomRegistry::state($owner)->isHtmlDocument;
    }

    public static function namedNodeMapItem(ObjectEntry $namedNodeMap, int $index): ?ObjectEntry
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::item() called on non-namednodemap in this compiler build');
        }

        return self::collectionItem($namedNodeMap, $index);
    }

    public static function namedNodeMapGetNamedItem(ObjectEntry $namedNodeMap, string $name): ?ObjectEntry
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::getNamedItem() called on non-namednodemap in this compiler build');
        }
        // php-src namednodemap.c — Attr maps use libxml xmlHasProp (local name); Entity/Notation
        // maps use xmlHashLookup by declaration name / nodeName (#20734, #24332).
        $state = DomRegistry::state($namedNodeMap);
        foreach ($state->listNodeIds as $nodeId) {
            $node = DomRegistry::entry($nodeId);
            if (null === $node) {
                continue;
            }
            if (self::isAttr($node)) {
                if (self::readLocalName($node) === $name) {
                    return $node;
                }
                continue;
            }
            if (DomRegistry::state($node)->nodeName === $name) {
                return $node;
            }
        }

        return null;
    }

    public static function namedNodeMapGetNamedItemNS(
        ObjectEntry $namedNodeMap,
        ?string $namespace,
        string $localName
    ): ?ObjectEntry {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::getNamedItemNS() called on non-namednodemap in this compiler build');
        }
        $wantNs = $namespace ?? '';
        $state = DomRegistry::state($namedNodeMap);
        foreach ($state->listNodeIds as $nodeId) {
            $node = DomRegistry::entry($nodeId);
            if (null === $node || !self::isAttr($node)) {
                continue;
            }
            $attrState = DomRegistry::state($node);
            $qName = $attrState->nodeName;
            if (self::isXmlnsAttributeName($qName)) {
                continue;
            }
            $attrLocal = $attrState->localName ?? self::attributeLocalName($qName);
            if ($attrLocal !== $localName) {
                continue;
            }
            $ownerElementId = $attrState->ownerElementId;
            if (null === $ownerElementId) {
                continue;
            }
            $ownerElement = DomRegistry::entry($ownerElementId);
            if (null === $ownerElement || !self::isElement($ownerElement)) {
                continue;
            }
            [$prefix] = self::splitQualifiedName($qName);
            if (self::resolveAttributeNamespaceUri($ownerElement, $qName, $prefix) === $wantNs) {
                return $node;
            }
        }

        return null;
    }

    public static function namedNodeMapRewind(ObjectEntry $namedNodeMap): void
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::rewind() called on non-namednodemap in this compiler build');
        }
        DomRegistry::state($namedNodeMap)->listIterIndex = 0;
    }

    public static function namedNodeMapValid(ObjectEntry $namedNodeMap): bool
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::valid() called on non-namednodemap in this compiler build');
        }
        $state = DomRegistry::state($namedNodeMap);

        return $state->listIterIndex < \count($state->listNodeIds);
    }

    public static function namedNodeMapCurrent(ObjectEntry $namedNodeMap): ?ObjectEntry
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::current() called on non-namednodemap in this compiler build');
        }
        $state = DomRegistry::state($namedNodeMap);
        if ($state->listIterIndex < 0 || $state->listIterIndex >= \count($state->listNodeIds)) {
            return null;
        }

        return self::namedNodeMapItem($namedNodeMap, $state->listIterIndex);
    }

    public static function namedNodeMapKey(ObjectEntry $namedNodeMap): ?string
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::key() called on non-namednodemap in this compiler build');
        }
        $state = DomRegistry::state($namedNodeMap);
        if ($state->listIterIndex < 0 || $state->listIterIndex >= \count($state->listNodeIds)) {
            return null;
        }
        $node = self::namedNodeMapItem($namedNodeMap, $state->listIterIndex);
        if (null === $node) {
            return null;
        }
        // php-src NamedNodeMap iteration keys Attr.name (local), not nodeName (QName).
        if (self::isAttr($node)) {
            return self::readLocalName($node);
        }

        return DomRegistry::state($node)->nodeName;
    }

    public static function namedNodeMapNext(ObjectEntry $namedNodeMap): void
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::next() called on non-namednodemap in this compiler build');
        }
        ++DomRegistry::state($namedNodeMap)->listIterIndex;
    }

    /**
     * DOMNamedNodeMap::getIterator() — live InternalIterator; keys are Attr local names
     * (#21298, #21466, #21931 live foreach + removeAttribute).
     */
    public static function namedNodeMapGetIterator(Context $ctx, ObjectEntry $namedNodeMap): ObjectEntry
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            throw new \LogicException('DOMNamedNodeMap::getIterator() called on non-namednodemap in this compiler build');
        }

        return InternalIteratorBuiltin::fromLiveHandler(
            $ctx,
            DomLiveNamedNodeMapIterator::forNamedNodeMap($namedNodeMap)
        );
    }

    /**
     * Shared InternalIterator factory for DOM NodeList / NamedNodeMap getIterator() (#21466).
     * TokenList still uses ArrayIterator via VmDomTokenList (separate surface).
     */
    private static function internalIteratorFromTable(Context $ctx, HashTable $ht): ObjectEntry
    {
        return InternalIteratorBuiltin::fromTable($ctx, $ht);
    }

    /**
     * @param list<int> $nodeIds
     * @param ObjectEntry|null $livingOwner When set on a living Dom\* node, allocate Dom\NamedNodeMap (#20948)
     *                                      or Dom\DtdNamedNodeMap when $dtdMap (#21014).
     * @param bool $dtdMap DocumentType entities/notations → Dom\DtdNamedNodeMap on living trees.
     */
    public static function createNamedNodeMap(
        Context $ctx,
        array $nodeIds,
        ?ObjectEntry $livingOwner = null,
        bool $dtdMap = false
    ): Variable {
        $classLc = self::CLASS_NAMED_NODE_MAP;
        if (null !== $livingOwner && VmDomLiving::prefersLivingCollections($livingOwner)) {
            if ($dtdMap && isset($ctx->classes[VmDomLiving::CLASS_DTD_NAMED_NODE_MAP])) {
                $classLc = VmDomLiving::CLASS_DTD_NAMED_NODE_MAP;
            } elseif (isset($ctx->classes[VmDomLiving::CLASS_NAMED_NODE_MAP])) {
                $classLc = VmDomLiving::CLASS_NAMED_NODE_MAP;
            }
        }
        $class = $ctx->classes[$classLc] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMNamedNodeMap is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NAMEDNODEMAP;
        $state->nodeName = $dtdMap && VmDomLiving::CLASS_DTD_NAMED_NODE_MAP === $classLc
            ? '#dtdnamednodemap'
            : '#namednodemap';
        $state->listNodeIds = $nodeIds;
        $state->listIterIndex = 0;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function isNamedNodeMap(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_NAMED_NODE_MAP !== $lc
            && VmDomLiving::CLASS_NAMED_NODE_MAP !== $lc
            && VmDomLiving::CLASS_DTD_NAMED_NODE_MAP !== $lc
        ) {
            return false;
        }

        return DomRegistry::has($entry)
            && DomConstants::XML_NAMEDNODEMAP === DomRegistry::state($entry)->nodeType;
    }

    /** Attr / element attribute maps — not DocumentType DtdNamedNodeMap (#21014). */
    public static function isAttrNamedNodeMap(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);

        return (self::CLASS_NAMED_NODE_MAP === $lc || VmDomLiving::CLASS_NAMED_NODE_MAP === $lc)
            && self::isNamedNodeMap($entry);
    }

    /** Dom\DtdNamedNodeMap — DocumentType entities/notations (#21014). */
    public static function isDtdNamedNodeMap(ObjectEntry $entry): bool
    {
        return VmDomLiving::CLASS_DTD_NAMED_NODE_MAP === strtolower($entry->class->name)
            && self::isNamedNodeMap($entry);
    }

    public static function createTokenList(Context $ctx, ObjectEntry $element): Variable
    {
        // php-src: Dom\Element::$classList → Dom\TokenList only (#28227, re-#20512).
        $class = $ctx->classes[VmDomLiving::CLASS_TOKEN_LIST] ?? null;
        if (null === $class) {
            throw new \LogicException('Dom\\TokenList is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_TOKENLIST;
        $state->nodeName = '#tokenlist';
        $state->tokenListElementId = $element->id;
        $state->tokenListTokens = VmDomTokenList::parseTokens(VmDomTokenList::elementClassValue($element));
        $state->tokenListCachedClassValue = VmDomTokenList::elementClassValue($element);
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function isTokenList(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_TOKEN_LIST !== $lc && VmDomLiving::CLASS_TOKEN_LIST !== $lc) {
            return false;
        }

        return DomRegistry::has($entry)
            && DomConstants::XML_TOKENLIST === DomRegistry::state($entry)->nodeType;
    }

    public static function isXPath(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);
        if (self::CLASS_XPATH !== $lc && VmDomLiving::CLASS_XPATH !== $lc) {
            return false;
        }

        return DomRegistry::has($entry)
            && DomConstants::XML_XPATH === DomRegistry::state($entry)->nodeType;
    }

    public static function syncElementClassList(Context $ctx, ObjectEntry $element): void
    {
        // Legacy DOMElement has no classList (php-src php_dom.stub.php; #28227).
        if (!CompilerVersion::supportsDomTokenList()
            || !self::isElement($element)
            || !VmDomLiving::isLivingElement($element)
        ) {
            return;
        }
        self::initElementPropertySlots($element);
        $state = DomRegistry::state($element);
        $classListVar = $element->getProperty(self::PROP_CLASS_LIST);
        if (null !== $state->classListId) {
            $tokenList = DomRegistry::entry($state->classListId);
            if (null !== $tokenList && self::isTokenList($tokenList)) {
                VmDomTokenList::invalidateForElement($element);
                $classListVar->object($tokenList);

                return;
            }
        }
        if (null === $state->classListId && Variable::TYPE_OBJECT === $classListVar->resolveIndirect()->type) {
            $existing = $classListVar->resolveIndirect()->toObject();
            if (self::isTokenList($existing)) {
                $state->classListId = $existing->id;
                DomRegistry::state($existing)->tokenListElementId = $element->id;
                VmDomTokenList::invalidateForElement($element);
                $classListVar->object($existing);

                return;
            }
        }
        $listVar = self::createTokenList($ctx, $element);
        $list = $listVar->toObject();
        $state->classListId = $list->id;
        $classListVar->copyFrom($listVar);
    }

    private static function collectionItem(ObjectEntry $collection, int $index): ?ObjectEntry
    {
        $ids = DomRegistry::state($collection)->listNodeIds;
        if (!isset($ids[$index])) {
            return null;
        }

        return DomRegistry::entry($ids[$index]);
    }

    private static function initDocumentTypePropertySlots(
        ObjectEntry $entry,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): void {
        $entry->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        $entry->getProperty(self::PROP_NAME)->string($qualifiedName);
        $entry->getProperty(self::PROP_PUBLIC_ID)->string($publicId);
        $entry->getProperty(self::PROP_SYSTEM_ID)->string($systemId);
        // createDocumentType / empty subset → null (php-src documenttype.c; #21000).
        $entry->getProperty(self::PROP_INTERNAL_SUBSET)->null();
        self::initNodePropertySlots($entry);
    }

    /**
     * Initialize nodeName/tagName slots before DomRegistry attach.
     * Living Dom\Element classes omit classic DOMElement ClassProperty declarations (#20418).
     */
    private static function writeElementNameSlots(ObjectEntry $entry, string $name): void
    {
        if (!$entry->hasProperty(self::PROP_NODE_NAME)) {
            $entry->allocateProperty(self::PROP_NODE_NAME)->string($name);
        } else {
            $entry->getProperty(self::PROP_NODE_NAME)->string($name);
        }
        if ($entry->hasProperty(self::PROP_TAG_NAME)) {
            $entry->getProperty(self::PROP_TAG_NAME)->string($name);
        } else {
            $entry->allocateProperty(self::PROP_TAG_NAME)->string($name);
        }
    }

    private static function initNodePropertySlots(ObjectEntry $entry): void
    {
        if (!$entry->hasProperty(self::PROP_FIRST_CHILD)) {
            $entry->allocateProperty(self::PROP_FIRST_CHILD)->null();
        }
        if (!$entry->hasProperty(self::PROP_LAST_CHILD)) {
            $entry->allocateProperty(self::PROP_LAST_CHILD)->null();
        }
        if (!$entry->hasProperty(self::PROP_NEXT_SIBLING)) {
            $entry->allocateProperty(self::PROP_NEXT_SIBLING)->null();
        }
        if (!$entry->hasProperty(self::PROP_PREVIOUS_SIBLING)) {
            $entry->allocateProperty(self::PROP_PREVIOUS_SIBLING)->null();
        }
        if (!$entry->hasProperty(self::PROP_PARENT_NODE)) {
            $entry->allocateProperty(self::PROP_PARENT_NODE)->null();
        }
        if (CompilerVersion::supportsDomParentElement() && !$entry->hasProperty(self::PROP_PARENT_ELEMENT)) {
            $entry->allocateProperty(self::PROP_PARENT_ELEMENT)->null();
        }
        if (!$entry->hasProperty(self::PROP_CHILD_NODES)) {
            $entry->allocateProperty(self::PROP_CHILD_NODES)->null();
        }
        if (!$entry->hasProperty(self::PROP_REGISTRY_ID)) {
            $entry->allocateProperty(self::PROP_REGISTRY_ID)->int(0);
        }
        // ParentNode props: Element / Document / DocumentFragment (#19431).
        if (self::isElement($entry) || self::isDocument($entry) || self::isDocumentFragment($entry)) {
            if (!$entry->hasProperty(self::PROP_FIRST_ELEMENT_CHILD)) {
                $entry->allocateProperty(self::PROP_FIRST_ELEMENT_CHILD)->null();
            }
            if (!$entry->hasProperty(self::PROP_LAST_ELEMENT_CHILD)) {
                $entry->allocateProperty(self::PROP_LAST_ELEMENT_CHILD)->null();
            }
            if (!$entry->hasProperty(self::PROP_CHILD_ELEMENT_COUNT)) {
                $entry->allocateProperty(self::PROP_CHILD_ELEMENT_COUNT)->int(0);
            }
            // Dom\* ParentNode::$children — PHP 8.5+ only (#21559, re-#21033).
            $props = $entry->propertiesWithNames();
            if (CompilerVersion::supportsDomParentNodeChildren()
                && str_starts_with(strtolower($entry->class->name), 'dom\\')
                && !isset($props[self::PROP_CHILDREN])
            ) {
                $entry->allocateProperty(self::PROP_CHILDREN)->null();
            }
        }
        // NonDocumentTypeChildNode: Element + CharacterData (Text/Comment/CDATA) (#19431).
        if (self::isElement($entry) || self::isCharacterData($entry)) {
            if (!$entry->hasProperty(self::PROP_NEXT_ELEMENT_SIBLING)) {
                $entry->allocateProperty(self::PROP_NEXT_ELEMENT_SIBLING)->null();
            }
            if (!$entry->hasProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING)) {
                $entry->allocateProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING)->null();
            }
        }
    }

    private static function initElementPropertySlots(ObjectEntry $entry): void
    {
        self::initNodePropertySlots($entry);
        if (!$entry->hasProperty(self::PROP_ATTRIBUTES)) {
            $entry->allocateProperty(self::PROP_ATTRIBUTES)->null();
        }
        if (CompilerVersion::supportsDomTokenList()
            && VmDomLiving::isLivingElement($entry)
            && !$entry->hasProperty(self::PROP_CLASS_LIST)
        ) {
            $entry->allocateProperty(self::PROP_CLASS_LIST)->null();
        }
    }

    /**
     * @return list<int>
     */
    private static function collectAttributeNodeIds(Context $ctx, ObjectEntry $element): array
    {
        $state = DomRegistry::state($element);
        $ids = [];
        foreach ($state->attributes as $name => $value) {
            $cachedId = $state->attributeNodeIds[$name] ?? null;
            if (null !== $cachedId) {
                $cached = DomRegistry::entry($cachedId);
                if (null !== $cached && self::isAttr($cached)) {
                    // Value-write collapse destroys Text+EntityReference Attr children
                    // (php-src setAttributeNode keeps them; #24512).
                    if (self::attrHasPreservedChildStructure($cached)) {
                        $cachedState = DomRegistry::state($cached);
                        $cachedState->textContent = $value;
                        if ($cached->hasProperty(self::PROP_VALUE)) {
                            $cached->getProperty(self::PROP_VALUE)->string($value);
                        }
                        if ($cached->hasProperty(self::PROP_NODE_VALUE)) {
                            $cached->getProperty(self::PROP_NODE_VALUE)->string($value);
                        }
                    } else {
                        self::syncAttributeNodeValue($ctx, $cached, $value, true);
                    }
                    $ids[] = $cachedId;

                    continue;
                }
            }
            $attr = self::attributeNodeForElement($ctx, $element, $name, $value);
            $ids[] = $attr->id;
        }

        return $ids;
    }

    /**
     * Attr with EntityReference children or multiple children — must not be collapsed
     * by syncAttributeNodeValue / ensureAttrValueTextChild (#24512).
     */
    private static function attrHasPreservedChildStructure(ObjectEntry $attr): bool
    {
        $childIds = DomRegistry::state($attr)->childIds;
        if (\count($childIds) > 1) {
            return true;
        }
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::isEntityReference($child)) {
                return true;
            }
        }

        return false;
    }

    /** Read DOMElement::$attributes without re-entering managed-property dispatch (#17619). */
    public static function elementAttributesVariable(ObjectEntry $element): Variable
    {
        $props = $element->propertiesWithNames();
        if (!isset($props[self::PROP_ATTRIBUTES])) {
            throw new \LogicException('DOMElement attributes property slot is missing');
        }
        $var = new Variable();
        $var->copyFrom($props[self::PROP_ATTRIBUTES]);

        return $var;
    }

    /** Zend dom_element_attributes_read — empty DOMNamedNodeMap before first attribute (ext/dom/attr.c; #17619). */
    public static function ensureElementAttributesMap(Context $ctx, ObjectEntry $element): void
    {
        if (!self::isElement($element) || !DomRegistry::has($element)) {
            return;
        }
        self::syncElementAttributes($ctx, $element);
    }

    /**
     * Property read for DOMElement::$attributes — fresh NamedNodeMap wrapper each time
     * (php-src ext/dom/php_dom.c dom_element_attributes_read; #26330).
     */
    public static function issueElementAttributesMap(Context $ctx, ObjectEntry $element): Variable
    {
        if (!self::isElement($element) || !DomRegistry::has($element)) {
            $var = new Variable();
            $var->null();

            return $var;
        }
        self::initElementPropertySlots($element);
        $state = DomRegistry::state($element);
        $attrIds = self::collectAttributeNodeIds($ctx, $element);
        self::syncAttributeTreeLinks($ctx, $element, $attrIds);
        self::refreshLiveAttributesMaps($element, $attrIds);

        $mapVar = self::createNamedNodeMap($ctx, $attrIds, $element);
        $map = $mapVar->toObject();
        $state->liveAttributesMapIds[] = $map->id;
        $state->attributesListId = $map->id;
        self::rawPropertySlot($element, self::PROP_ATTRIBUTES)->copyFrom($mapVar);

        $out = new Variable();
        $out->copyFrom($mapVar);

        return $out;
    }

    /**
     * Property read for DOMNode::$childNodes — fresh NodeList wrapper each time
     * (php-src ext/dom/node.c dom_node_child_nodes_read; #26330).
     */
    public static function issueChildNodesList(Context $ctx, ObjectEntry $node): Variable
    {
        if (!self::exposesChildNodes($node)) {
            $var = new Variable();
            $var->null();

            return $var;
        }
        self::initNodePropertySlots($node);
        $state = DomRegistry::state($node);
        self::syncChildSiblingLinks($state->childIds);
        self::refreshLiveChildNodesLists($node, $state->childIds);

        $listVar = VmDomLiving::prefersLivingCollections($node)
            ? self::createDomNodeList($ctx, $state->childIds)
            : self::createNodeList($ctx, $state->childIds);
        $list = $listVar->toObject();
        $state->liveChildNodesListIds[] = $list->id;
        $state->childNodesListId = $list->id;
        self::rawPropertySlot($node, self::PROP_CHILD_NODES)->copyFrom($listVar);

        $out = new Variable();
        $out->copyFrom($listVar);

        return $out;
    }

    /** @param list<int> $attrIds */
    private static function refreshLiveAttributesMaps(ObjectEntry $element, array $attrIds): void
    {
        $state = DomRegistry::state($element);
        $alive = [];
        foreach ($state->liveAttributesMapIds as $mapId) {
            $map = DomRegistry::entry($mapId);
            if (null === $map || !self::isNamedNodeMap($map)) {
                continue;
            }
            self::updateNamedNodeMapMembers($map, $attrIds);
            $alive[] = $mapId;
        }
        $state->liveAttributesMapIds = $alive;
        if (null !== $state->attributesListId
            && !\in_array($state->attributesListId, $alive, true)
        ) {
            $canonical = DomRegistry::entry($state->attributesListId);
            if (null !== $canonical && self::isNamedNodeMap($canonical)) {
                self::updateNamedNodeMapMembers($canonical, $attrIds);
                $state->liveAttributesMapIds[] = $state->attributesListId;
            }
        }
    }

    /** @param list<int> $childIds */
    private static function refreshLiveChildNodesLists(ObjectEntry $node, array $childIds): void
    {
        $state = DomRegistry::state($node);
        $alive = [];
        foreach ($state->liveChildNodesListIds as $listId) {
            $list = DomRegistry::entry($listId);
            if (null === $list || !self::isNodeList($list)) {
                continue;
            }
            self::updateNodeListMembers($list, $childIds);
            $alive[] = $listId;
        }
        $state->liveChildNodesListIds = $alive;
        if (null !== $state->childNodesListId
            && !\in_array($state->childNodesListId, $alive, true)
        ) {
            $canonical = DomRegistry::entry($state->childNodesListId);
            if (null !== $canonical && self::isNodeList($canonical)) {
                self::updateNodeListMembers($canonical, $childIds);
                $state->liveChildNodesListIds[] = $state->childNodesListId;
            }
        }
    }

    /** Slot access that does not re-enter managed property handlers (#26330). */
    private static function rawPropertySlot(ObjectEntry $entry, string $name): Variable
    {
        $props = $entry->propertiesWithNames();
        if (!isset($props[$name])) {
            $entry->allocateProperty($name);
            $props = $entry->propertiesWithNames();
        }

        return $props[$name];
    }

    private static function syncElementAttributes(Context $ctx, ObjectEntry $element): void
    {
        if (!self::isElement($element)) {
            return;
        }
        self::initElementPropertySlots($element);
        $state = DomRegistry::state($element);
        $attrIds = self::collectAttributeNodeIds($ctx, $element);
        self::syncAttributeTreeLinks($ctx, $element, $attrIds);

        $attrsVar = self::rawPropertySlot($element, self::PROP_ATTRIBUTES);
        self::refreshLiveAttributesMaps($element, $attrIds);

        if (null !== $state->attributesListId) {
            $map = DomRegistry::entry($state->attributesListId);
            if (null !== $map && self::isNamedNodeMap($map)) {
                self::updateNamedNodeMapMembers($map, $attrIds);
                $attrsVar->object($map);

                return;
            }
        }
        if (Variable::TYPE_OBJECT === $attrsVar->resolveIndirect()->type) {
            $existing = $attrsVar->resolveIndirect()->toObject();
            if (self::isNamedNodeMap($existing)) {
                $state->attributesListId = $existing->id;
                if (!\in_array($existing->id, $state->liveAttributesMapIds, true)) {
                    $state->liveAttributesMapIds[] = $existing->id;
                }
                self::updateNamedNodeMapMembers($existing, $attrIds);
                $attrsVar->object($existing);

                return;
            }
        }
        $mapVar = self::createNamedNodeMap($ctx, $attrIds, $element);
        $map = $mapVar->toObject();
        $state->attributesListId = $map->id;
        $state->liveAttributesMapIds[] = $map->id;
        $attrsVar->copyFrom($mapVar);
    }

    /**
     * Link Attr parent/siblings like libxml xmlAttr (php-src ext/dom/node.c; #20501).
     *
     * @param list<int> $attrIds
     */
    private static function syncAttributeTreeLinks(Context $ctx, ObjectEntry $element, array $attrIds): void
    {
        foreach ($attrIds as $attrId) {
            $attr = DomRegistry::entry($attrId);
            if (null === $attr || !self::isAttr($attr)) {
                continue;
            }
            $attrState = DomRegistry::state($attr);
            $attrState->ownerElementId = $element->id;
            $attrState->parentId = $element->id;
            if ($attr->hasProperty(self::PROP_OWNER_ELEMENT)) {
                $attr->getProperty(self::PROP_OWNER_ELEMENT)->object($element);
            }
        }
        self::syncChildSiblingLinks($attrIds);
        foreach ($attrIds as $attrId) {
            $attr = DomRegistry::entry($attrId);
            if (null === $attr || !self::isAttr($attr)) {
                continue;
            }
            self::syncNodeLinks($ctx, $attr);
        }
    }

    /** @param list<int> $nodeIds */
    private static function updateNamedNodeMapMembers(ObjectEntry $namedNodeMap, array $nodeIds): void
    {
        if (!self::isNamedNodeMap($namedNodeMap)) {
            return;
        }
        $state = DomRegistry::state($namedNodeMap);
        $state->listNodeIds = $nodeIds;
        $state->listIterIndex = 0;
        $namedNodeMap->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));
    }

    private static function linkChildToParent(ObjectEntry $child, ?ObjectEntry $parent): void
    {
        $childState = DomRegistry::state($child);
        $childState->parentId = null !== $parent ? $parent->id : null;
        if (null !== $parent) {
            $parentState = DomRegistry::state($parent);
            if (self::isDocument($parent)) {
                $childState->documentId = $parent->id;
            } elseif (null !== $parentState->documentId) {
                $childState->documentId = $parentState->documentId;
            }

            return;
        }
        // Detached nodes clear parent/sibling slots (php-src php_dom_unlink_node; #19240).
        // syncSubtree(parent) only walks remaining childIds — it never refreshes the unlinked node.
        self::clearDetachedNodeLinkProperties($child);
    }

    /** Clear live parent/sibling props after unlink (ext/dom/node.c; #19240). */
    private static function clearDetachedNodeLinkProperties(ObjectEntry $node): void
    {
        self::initNodePropertySlots($node);
        $node->getProperty(self::PROP_PARENT_NODE)->null();
        if (CompilerVersion::supportsDomParentElement() && $node->hasProperty(self::PROP_PARENT_ELEMENT)) {
            $node->getProperty(self::PROP_PARENT_ELEMENT)->null();
        }
        $node->getProperty(self::PROP_NEXT_SIBLING)->null();
        $node->getProperty(self::PROP_PREVIOUS_SIBLING)->null();
        if ($node->hasProperty(self::PROP_NEXT_ELEMENT_SIBLING)) {
            $node->getProperty(self::PROP_NEXT_ELEMENT_SIBLING)->null();
        }
        if ($node->hasProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING)) {
            $node->getProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING)->null();
        }
    }

    private static function assertMutationParent(ObjectEntry $parent): void
    {
        if (!DomRegistry::has($parent)) {
            throw new \DOMException('Hierarchy request error');
        }
        $nodeType = DomRegistry::state($parent)->nodeType;
        if (DomConstants::XML_ELEMENT_NODE !== $nodeType
            && DomConstants::XML_DOCUMENT_NODE !== $nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $nodeType
            // Legacy DOM Attr is a valid mutation parent for Text/EntityReference (#24512).
            && DomConstants::XML_ATTRIBUTE_NODE !== $nodeType
        ) {
            throw new \DOMException('Hierarchy request error');
        }
    }

    private static function assertChildOfParent(ObjectEntry $parent, ObjectEntry $child, string $label): void
    {
        if (!DomRegistry::has($child)) {
            DomExceptionConstants::raiseNotFound();
        }
        $childState = DomRegistry::state($child);
        if ($childState->parentId !== $parent->id) {
            DomExceptionConstants::raiseNotFound();
        }
        if (!\in_array($child->id, DomRegistry::state($parent)->childIds, true)) {
            DomExceptionConstants::raiseNotFound();
        }
    }

    private static function assertSameDocument(ObjectEntry $parent, ObjectEntry $child): void
    {
        $parentDocId = self::resolveDocumentId($parent);
        $childDocId = self::resolveDocumentId($child);
        if (null !== $parentDocId && null !== $childDocId && $parentDocId !== $childDocId) {
            DomExceptionConstants::raiseWrongDocument();
        }
    }

    /**
     * Reject inserting a node that is an ancestor of (or identical to) the parent.
     * Without this guard, detach+relink forms a cycle and syncSubtree hangs (php-src
     * ext/dom/node.c hierarchy check; #19753).
     */
    private static function assertNotAncestorOfParent(ObjectEntry $parent, ObjectEntry $child): void
    {
        if (self::contains($child, $parent)) {
            throw new \DOMException(
                'Hierarchy Request Error',
                DomExceptionConstants::HIERARCHY_REQUEST_ERR
            );
        }
    }

    private static function resolveDocumentId(ObjectEntry $node): ?int
    {
        if (self::isDocument($node)) {
            return $node->id;
        }
        if (!DomRegistry::has($node)) {
            return null;
        }

        return DomRegistry::state($node)->documentId;
    }

    private static function detachNodeIfAttached(Context $ctx, ObjectEntry $node): void
    {
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            return;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            self::linkChildToParent($node, null);

            return;
        }
        self::removeChild($ctx, $parent, $node);
    }

    /** @param list<int> $childIds */
    private static function childIndex(array $childIds, int $childId): ?int
    {
        $index = \array_search($childId, $childIds, true);

        return false === $index ? null : (int) $index;
    }

    private static function propagateDocumentId(ObjectEntry $node, int $documentId): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            $state->documentId = $documentId;
        }
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::propagateDocumentId($child, $documentId);
            }
        }
    }

    /** Zend dom_node_child_nodes_read — empty DOMNodeList before first mutation (ext/dom/node.c; #17617). */
    public static function ensureChildNodesList(Context $ctx, ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        if (!self::isElement($node)
            && !self::isDocument($node)
            && !self::isDocumentFragment($node)
        ) {
            return;
        }
        self::initNodePropertySlots($node);
        // Raw slot — childNodes is a managed property (#26330).
        $childNodesVar = self::rawPropertySlot($node, self::PROP_CHILD_NODES)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $childNodesVar->type && self::isNodeList($childNodesVar->toObject())) {
            self::ensureChildrenCollection($ctx, $node);

            return;
        }
        self::syncNodeLinks($ctx, $node);
    }

    /**
     * Dom\* ParentNode::$children — empty HTMLCollection before first mutation
     * (php-src ext/dom/html_collection.c / parentnode; #21033).
     */
    public static function ensureChildrenCollection(Context $ctx, ObjectEntry $node): void
    {
        if (!self::isLivingParentNodeForChildren($node)) {
            return;
        }
        self::initNodePropertySlots($node);
        $props = $node->propertiesWithNames();
        if (!isset($props[self::PROP_CHILDREN])) {
            $node->allocateProperty(self::PROP_CHILDREN)->null();
            $props = $node->propertiesWithNames();
        }
        $childrenVar = $props[self::PROP_CHILDREN]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $childrenVar->type && self::isHtmlCollection($childrenVar->toObject())) {
            return;
        }
        self::syncParentNodeChildrenCollection($ctx, $node, DomRegistry::state($node)->childIds);
    }

    /** Read ParentNode::$children without re-entering managed-property dispatch (#21033). */
    public static function parentNodeChildrenVariable(ObjectEntry $node): Variable
    {
        $props = $node->propertiesWithNames();
        if (!isset($props[self::PROP_CHILDREN])) {
            throw new \LogicException('ParentNode children property slot is missing');
        }
        $var = new Variable();
        $var->copyFrom($props[self::PROP_CHILDREN]);

        return $var;
    }

    /** True for Dom\Element / Document / DocumentFragment ParentNode::$children (#21559, re-#21033). */
    public static function isLivingParentNodeForChildren(ObjectEntry $node): bool
    {
        if (!CompilerVersion::supportsDomParentNodeChildren()) {
            return false;
        }
        if (!DomRegistry::has($node)) {
            return false;
        }
        if (!self::isElement($node) && !self::isDocument($node) && !self::isDocumentFragment($node)) {
            return false;
        }
        $lc = strtolower($node->class->name);

        return str_starts_with($lc, 'dom\\');
    }

    private static function syncSubtree(Context $ctx, ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        self::syncNodeLinks($ctx, $node);
        $state = DomRegistry::state($node);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::syncSubtree($ctx, $child);
            }
        }
    }

    /** Mirror live child links onto a user-script handle that aliases DomRegistry (#18951). */
    public static function mirrorNodeLinkProperties(ObjectEntry $dest, ObjectEntry $source): void
    {
        if ($dest->id !== $source->id) {
            return;
        }
        self::initNodePropertySlots($dest);
        self::initNodePropertySlots($source);
        foreach ([
            self::PROP_FIRST_CHILD,
            self::PROP_LAST_CHILD,
            self::PROP_CHILD_NODES,
        ] as $prop) {
            // childNodes is managed (#26330) — copy the raw slot, do not issue a wrapper.
            if (self::PROP_CHILD_NODES === $prop) {
                self::rawPropertySlot($dest, $prop)->copyFrom(self::rawPropertySlot($source, $prop));

                continue;
            }
            $dest->getProperty($prop)->copyFrom($source->getProperty($prop));
        }
    }

    private static function syncNodeLinks(Context $ctx, ObjectEntry $node): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        self::initNodePropertySlots($node);
        $state = DomRegistry::state($node);

        $parentVar = $node->getProperty(self::PROP_PARENT_NODE);
        if (null !== $state->parentId) {
            $parent = DomRegistry::entry($state->parentId);
            if (null !== $parent) {
                $parentVar->object($parent);
            } else {
                $parentVar->null();
            }
        } else {
            $parentVar->null();
        }

        if (CompilerVersion::supportsDomParentElement()) {
            $parentElementVar = $node->getProperty(self::PROP_PARENT_ELEMENT);
            if (null !== $state->parentId) {
                $parent = DomRegistry::entry($state->parentId);
                if (null !== $parent && self::isElement($parent)) {
                    $parentElementVar->object($parent);
                } else {
                    $parentElementVar->null();
                }
            } else {
                $parentElementVar->null();
            }
        }

        $firstVar = $node->getProperty(self::PROP_FIRST_CHILD);
        $lastVar = $node->getProperty(self::PROP_LAST_CHILD);
        if ([] === $state->childIds) {
            $firstVar->null();
            $lastVar->null();
        } else {
            $first = DomRegistry::entry($state->childIds[0]);
            $last = DomRegistry::entry($state->childIds[\count($state->childIds) - 1]);
            if (null !== $first) {
                $firstVar->object($first);
            } else {
                $firstVar->null();
            }
            if (null !== $last) {
                $lastVar->object($last);
            } else {
                $lastVar->null();
            }
        }

        self::syncParentNodeElementProperties($node, $state->childIds);
        self::syncParentNodeChildrenCollection($ctx, $node, $state->childIds);

        // Raw slot — childNodes is a managed property that issues fresh wrappers (#26330).
        $childNodesVar = self::rawPropertySlot($node, self::PROP_CHILD_NODES);
        self::refreshLiveChildNodesLists($node, $state->childIds);
        if (null !== $state->childNodesListId) {
            $list = DomRegistry::entry($state->childNodesListId);
            if (null !== $list && self::isNodeList($list)) {
                self::updateNodeListMembers($list, $state->childIds);
                $childNodesVar->object($list);
                self::syncChildSiblingLinks($state->childIds);
                if (self::isElement($node)) {
                    self::syncElementAttributes($ctx, $node);
                }

                return;
            }
        }
        if (Variable::TYPE_OBJECT === $childNodesVar->resolveIndirect()->type) {
            $existing = $childNodesVar->resolveIndirect()->toObject();
            if (self::isNodeList($existing)) {
                $state->childNodesListId = $existing->id;
                if (!\in_array($existing->id, $state->liveChildNodesListIds, true)) {
                    $state->liveChildNodesListIds[] = $existing->id;
                }
                self::updateNodeListMembers($existing, $state->childIds);
                $childNodesVar->object($existing);
                self::syncChildSiblingLinks($state->childIds);
                if (self::isElement($node)) {
                    self::syncElementAttributes($ctx, $node);
                }

                return;
            }
        }
        if (null === $node->class->parentLc
            && !self::isElement($node)
            && !self::isDocument($node)
            && !self::isDocumentFragment($node)
        ) {
            return;
        }
        $listVar = VmDomLiving::prefersLivingCollections($node)
            ? self::createDomNodeList($ctx, $state->childIds)
            : self::createNodeList($ctx, $state->childIds);
        $list = $listVar->toObject();
        $state->childNodesListId = $list->id;
        $state->liveChildNodesListIds[] = $list->id;
        $childNodesVar->copyFrom($listVar);

        self::syncChildSiblingLinks($state->childIds);

        if (self::isElement($node)) {
            self::syncElementAttributes($ctx, $node);
        }
    }

    /** @param list<int> $childIds */
    private static function siblingEntry(ObjectEntry $node, string $prop): ?ObjectEntry
    {
        if (!$node->hasProperty($prop)) {
            return null;
        }
        $resolved = $node->getProperty($prop)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return null;
        }

        return $resolved->toObject();
    }

    private static function syncChildSiblingLinks(array $childIds): void
    {
        foreach ($childIds as $index => $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            self::initNodePropertySlots($child);
            $siblingVar = $child->getProperty(self::PROP_NEXT_SIBLING);
            $prevVar = $child->getProperty(self::PROP_PREVIOUS_SIBLING);
            $prevId = $childIds[$index - 1] ?? null;
            if (null !== $prevId) {
                $prev = DomRegistry::entry($prevId);
                if (null !== $prev) {
                    $prevVar->object($prev);
                } else {
                    $prevVar->null();
                }
            } else {
                $prevVar->null();
            }
            $nextId = $childIds[$index + 1] ?? null;
            if (null !== $nextId) {
                $next = DomRegistry::entry($nextId);
                if (null !== $next) {
                    $siblingVar->object($next);
                } else {
                    $siblingVar->null();
                }
            } else {
                $siblingVar->null();
            }
            self::syncElementSiblingProperties($child, $childIds, $index);
        }
    }

    /**
     * ParentNode first/lastElementChild + childElementCount (ext/dom/parentnode.c; #19431).
     *
     * @param list<int> $childIds
     */
    private static function syncParentNodeElementProperties(ObjectEntry $node, array $childIds): void
    {
        if (!self::isElement($node) && !self::isDocument($node) && !self::isDocumentFragment($node)) {
            return;
        }
        if (!$node->hasProperty(self::PROP_FIRST_ELEMENT_CHILD)) {
            return;
        }
        $firstEl = self::firstElementChildEntry($childIds);
        $lastEl = self::lastElementChildEntry($childIds);
        $count = self::countElementChildren($childIds);
        $firstVar = $node->getProperty(self::PROP_FIRST_ELEMENT_CHILD);
        $lastVar = $node->getProperty(self::PROP_LAST_ELEMENT_CHILD);
        $countVar = $node->getProperty(self::PROP_CHILD_ELEMENT_COUNT);
        if (null !== $firstEl) {
            $firstVar->object($firstEl);
        } else {
            $firstVar->null();
        }
        if (null !== $lastEl) {
            $lastVar->object($lastEl);
        } else {
            $lastVar->null();
        }
        $countVar->int($count);
    }

    /**
     * Dom\* ParentNode::$children live HTMLCollection (php-src html_collection.c; #21033).
     *
     * @param list<int> $childIds
     */
    private static function syncParentNodeChildrenCollection(Context $ctx, ObjectEntry $node, array $childIds): void
    {
        if (!self::isLivingParentNodeForChildren($node)) {
            return;
        }
        $props = $node->propertiesWithNames();
        if (!isset($props[self::PROP_CHILDREN])) {
            $node->allocateProperty(self::PROP_CHILDREN)->null();
            $props = $node->propertiesWithNames();
        }
        $elementIds = self::collectDirectElementChildIds($childIds);
        $state = DomRegistry::state($node);
        $childrenVar = $props[self::PROP_CHILDREN];

        if (null !== $state->childrenListId) {
            $list = DomRegistry::entry($state->childrenListId);
            if (null !== $list && self::isHtmlCollection($list)) {
                self::updateNodeListMembers($list, $elementIds);
                $childrenVar->object($list);

                return;
            }
        }
        if (null === $state->childrenListId && Variable::TYPE_OBJECT === $childrenVar->resolveIndirect()->type) {
            $existing = $childrenVar->resolveIndirect()->toObject();
            if (self::isHtmlCollection($existing)) {
                $state->childrenListId = $existing->id;
                self::updateNodeListMembers($existing, $elementIds);
                $childrenVar->object($existing);

                return;
            }
        }
        $listVar = self::createHtmlCollection($ctx, $elementIds);
        $list = $listVar->toObject();
        $state->childrenListId = $list->id;
        $childrenVar->copyFrom($listVar);
    }

    /**
     * Direct element child object ids (ParentNode::$children; #21033).
     *
     * @param list<int> $childIds
     *
     * @return list<int>
     */
    private static function collectDirectElementChildIds(array $childIds): array
    {
        $ids = [];
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::isElement($child)) {
                $ids[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * NonDocumentTypeChildNode next/previousElementSibling (ext/dom/nodelist.c; #19431).
     *
     * @param list<int> $childIds
     */
    private static function syncElementSiblingProperties(ObjectEntry $child, array $childIds, int $index): void
    {
        if (!self::isElement($child) && !self::isCharacterData($child)) {
            return;
        }
        if (!$child->hasProperty(self::PROP_NEXT_ELEMENT_SIBLING)) {
            return;
        }
        $nextEl = self::elementSiblingFromIndex($childIds, $index, true);
        $prevEl = self::elementSiblingFromIndex($childIds, $index, false);
        $nextVar = $child->getProperty(self::PROP_NEXT_ELEMENT_SIBLING);
        $prevVar = $child->getProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING);
        if (null !== $nextEl) {
            $nextVar->object($nextEl);
        } else {
            $nextVar->null();
        }
        if (null !== $prevEl) {
            $prevVar->object($prevEl);
        } else {
            $prevVar->null();
        }
    }

    /** @param list<int> $childIds */
    private static function firstElementChildEntry(array $childIds): ?ObjectEntry
    {
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::isElement($child)) {
                return $child;
            }
        }

        return null;
    }

    /** @param list<int> $childIds */
    private static function lastElementChildEntry(array $childIds): ?ObjectEntry
    {
        for ($i = \count($childIds) - 1; $i >= 0; --$i) {
            $child = DomRegistry::entry($childIds[$i]);
            if (null !== $child && self::isElement($child)) {
                return $child;
            }
        }

        return null;
    }

    /** @param list<int> $childIds */
    private static function countElementChildren(array $childIds): int
    {
        $count = 0;
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::isElement($child)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<int> $childIds
     */
    private static function elementSiblingFromIndex(array $childIds, int $index, bool $next): ?ObjectEntry
    {
        if ($next) {
            for ($i = $index + 1, $n = \count($childIds); $i < $n; ++$i) {
                $sib = DomRegistry::entry($childIds[$i]);
                if (null !== $sib && self::isElement($sib)) {
                    return $sib;
                }
            }

            return null;
        }
        for ($i = $index - 1; $i >= 0; --$i) {
            $sib = DomRegistry::entry($childIds[$i]);
            if (null !== $sib && self::isElement($sib)) {
                return $sib;
            }
        }

        return null;
    }

    /**
     * Refresh live NodeList membership without resetting the Iterator cursor.
     *
     * php-src ext/dom/nodelist.c keeps the traversal index across live updates;
     * resetting here made getElementsByTagName() foreach hang (valid() → refresh → index 0).
     *
     * @param list<int> $nodeIds
     */
    private static function updateNodeListMembers(ObjectEntry $nodeList, array $nodeIds): void
    {
        if (!self::isNodeList($nodeList)) {
            return;
        }
        $state = DomRegistry::state($nodeList);
        $state->listNodeIds = $nodeIds;
        if (isset($nodeList->properties[self::PROP_LENGTH])) {
            $nodeList->properties[self::PROP_LENGTH]->int(\count($nodeIds));
        }
    }

    /**
     * @param list<int> $matches
     */
    private static function collectElementsByTagNameFromChildren(
        ObjectEntry $node,
        ?string $want,
        array &$matches
    ): void {
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementsByTagNameRecursive($child, $want, $matches);
            }
        }
    }

    /**
     * @param list<string> $want
     * @param list<int>    $matches
     */
    private static function collectElementsByClassNameFromChildren(
        ObjectEntry $node,
        array $want,
        array &$matches
    ): void {
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementsByClassNameRecursive($child, $want, $matches);
            }
        }
    }

    /**
     * @param list<string> $want
     * @param list<int>    $matches
     */
    private static function collectElementsByClassNameRecursive(
        ObjectEntry $node,
        array $want,
        array &$matches
    ): void {
        if (!DomRegistry::has($node)) {
            return;
        }
        if (self::isElement($node) && self::elementMatchesClassNames($node, $want)) {
            $matches[] = $node->id;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementsByClassNameRecursive($child, $want, $matches);
            }
        }
    }

    /**
     * @param list<string> $want
     */
    private static function elementMatchesClassNames(ObjectEntry $element, array $want): bool
    {
        $have = VmDomTokenList::parseTokens(VmDomTokenList::elementClassValue($element));
        if ([] === $have) {
            return false;
        }
        foreach ($want as $token) {
            if (!\in_array($token, $have, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $matches
     */
    private static function collectElementsByTagNameRecursive(
        ObjectEntry $node,
        ?string $want,
        array &$matches
    ): void {
        if (self::isElement($node)) {
            $name = self::readLocalName($node);
            if (null === $want || $name === $want) {
                $matches[] = $node->id;
            }
        }
        self::collectElementsByTagNameFromChildren($node, $want, $matches);
    }

    /**
     * @param list<int> $matches
     */
    private static function collectElementsByTagNameNSFromChildren(
        ObjectEntry $node,
        string $namespaceUri,
        string $localName,
        array &$matches
    ): void {
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::collectElementsByTagNameNSRecursive($child, $namespaceUri, $localName, $matches);
            }
        }
    }

    /**
     * @param list<int> $matches
     */
    private static function collectElementsByTagNameNSRecursive(
        ObjectEntry $node,
        string $namespaceUri,
        string $localName,
        array &$matches
    ): void {
        if (self::isElement($node)) {
            $ns = self::readNamespaceUri($node) ?? '';
            $name = self::readLocalName($node);
            $nsMatch = '*' === $namespaceUri || $ns === $namespaceUri;
            $nameMatch = '*' === $localName || $name === $localName;
            if ($nsMatch && $nameMatch) {
                $matches[] = $node->id;
            }
        }
        self::collectElementsByTagNameNSFromChildren($node, $namespaceUri, $localName, $matches);
    }

    public static function isDomNode(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry);
    }

    /**
     * Class-hierarchy instanceof DOMNode (php-src zend_parse_parameters / stub union).
     * Prefer this over {@see isDomNode()} for TypeError gates — registries also hold
     * non-node helpers in some paths (#20291).
     */
    public static function isDomNodeInstance(ObjectEntry $entry, Context $ctx): bool
    {
        $class = $entry->class;
        for ($guard = 0; null !== $class && $guard < 64; ++$guard) {
            $lc = strtolower($class->name);
            // Classic DOMNode or living Dom\Node (#20418).
            if (self::CLASS_NODE === $lc || VmDomLiving::CLASS_NODE === $lc) {
                return true;
            }
            if (null === $class->parentLc || !isset($ctx->classes[$class->parentLc])) {
                return false;
            }
            $class = $ctx->classes[$class->parentLc];
        }

        return false;
    }

    public static function isSameNode(ObjectEntry $node, ObjectEntry $other): bool
    {
        return $node->id === $other->id;
    }

    public static function isEqualNode(ObjectEntry $node, ObjectEntry $other): bool
    {
        if ($node->id === $other->id) {
            return true;
        }
        if (!DomRegistry::has($node) || !DomRegistry::has($other)) {
            return false;
        }
        $stateA = DomRegistry::state($node);
        $stateB = DomRegistry::state($other);
        if ($stateA->nodeType !== $stateB->nodeType) {
            return false;
        }
        if ($stateA->nodeName !== $stateB->nodeName) {
            return false;
        }
        if (self::readNamespaceUri($node) !== self::readNamespaceUri($other)) {
            return false;
        }
        if (self::readLocalName($node) !== self::readLocalName($other)) {
            return false;
        }
        if (self::readPrefix($node) !== self::readPrefix($other)) {
            return false;
        }

        if (DomConstants::XML_ATTRIBUTE_NODE === $stateA->nodeType) {
            return self::readNodeValue($node) === self::readNodeValue($other);
        }
        if (DomConstants::XML_TEXT_NODE === $stateA->nodeType) {
            return ($stateA->textContent ?? '') === ($stateB->textContent ?? '');
        }
        if (DomConstants::XML_DOCUMENT_TYPE_NODE === $stateA->nodeType) {
            return ($stateA->publicId ?? '') === ($stateB->publicId ?? '')
                && ($stateA->systemId ?? '') === ($stateB->systemId ?? '');
        }
        if (self::isElement($node)) {
            if (!self::elementAttributesEqual($node, $other)) {
                return false;
            }
        }

        if (\count($stateA->childIds) !== \count($stateB->childIds)) {
            return false;
        }
        foreach ($stateA->childIds as $i => $childIdA) {
            $childIdB = $stateB->childIds[$i];
            $childA = DomRegistry::entry($childIdA);
            $childB = DomRegistry::entry($childIdB);
            if (null === $childA || null === $childB) {
                return false;
            }
            if (!self::isEqualNode($childA, $childB)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private static function normalizedElementAttributes(ObjectEntry $element): array
    {
        $state = DomRegistry::state($element);
        $attrs = $state->attributes;
        ksort($attrs);

        return $attrs;
    }

    private static function elementAttributesEqual(ObjectEntry $a, ObjectEntry $b): bool
    {
        return self::normalizedElementAttributes($a) === self::normalizedElementAttributes($b);
    }

    public static function hasChildNodes(ObjectEntry $node): bool
    {
        if (!DomRegistry::has($node)) {
            return false;
        }

        return [] !== DomRegistry::state($node)->childIds;
    }

    public static function hasAttributes(ObjectEntry $node): bool
    {
        if (!self::isElement($node)) {
            return false;
        }
        $state = DomRegistry::state($node);
        foreach ($state->attributes as $qName => $value) {
            if (!self::isXmlnsAttributeName($qName)) {
                return true;
            }
        }

        return false;
    }

    public static function compareDocumentPosition(ObjectEntry $node, ObjectEntry $other): int
    {
        if ($node->id === $other->id) {
            return 0;
        }
        if (!DomRegistry::has($node) || !DomRegistry::has($other)) {
            return self::disconnectedDocumentPosition($node, $other);
        }

        $root1 = self::getTreeRoot($node);
        $root2 = self::getTreeRoot($other);
        if ($root1->id !== $root2->id) {
            return self::disconnectedDocumentPosition($node, $other);
        }

        // php-src ext/dom/node.c: node1=other, node2=this — when this is ancestor of
        // other → CONTAINED_BY|FOLLOWING; when other is ancestor of this → CONTAINS|PRECEDING (#25878).
        if (self::contains($node, $other)) {
            return DomConstants::DOCUMENT_POSITION_CONTAINED_BY | DomConstants::DOCUMENT_POSITION_FOLLOWING;
        }
        if (self::contains($other, $node)) {
            return DomConstants::DOCUMENT_POSITION_CONTAINS | DomConstants::DOCUMENT_POSITION_PRECEDING;
        }

        $orderNode = self::documentOrderIndex($root1, $node);
        $orderOther = self::documentOrderIndex($root1, $other);
        if ($orderNode < 0 || $orderOther < 0) {
            return self::disconnectedDocumentPosition($node, $other);
        }
        if ($orderNode < $orderOther) {
            return DomConstants::DOCUMENT_POSITION_FOLLOWING;
        }

        return DomConstants::DOCUMENT_POSITION_PRECEDING;
    }

    private static function disconnectedDocumentPosition(ObjectEntry $node, ObjectEntry $other): int
    {
        $ordering = $node->id < $other->id
            ? DomConstants::DOCUMENT_POSITION_PRECEDING
            : DomConstants::DOCUMENT_POSITION_FOLLOWING;

        return DomConstants::DOCUMENT_POSITION_DISCONNECTED
            | DomConstants::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC
            | $ordering;
    }

    private static function getTreeRoot(ObjectEntry $node): ObjectEntry
    {
        $current = $node;
        while (DomRegistry::has($current)) {
            $state = DomRegistry::state($current);
            if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
                return $current;
            }
            if (null === $state->parentId) {
                return $current;
            }
            $parent = DomRegistry::entry($state->parentId);
            if (null === $parent) {
                return $current;
            }
            $current = $parent;
        }

        return $current;
    }

    private static function documentOrderIndex(ObjectEntry $root, ObjectEntry $target): int
    {
        $counter = 0;
        $found = -1;
        self::walkDocumentOrder($root, $target, $counter, $found);

        return $found;
    }

    private static function walkDocumentOrder(
        ObjectEntry $node,
        ObjectEntry $target,
        int &$counter,
        int &$found
    ): void {
        if ($node->id === $target->id) {
            $found = $counter;

            return;
        }
        ++$counter;
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                self::walkDocumentOrder($child, $target, $counter, $found);
                if ($found >= 0) {
                    return;
                }
            }
        }
    }

    public static function contains(ObjectEntry $node, ?ObjectEntry $other): bool
    {
        if (null === $other) {
            return false;
        }
        if ($node->id === $other->id) {
            return true;
        }
        if (!DomRegistry::has($node) || !DomRegistry::has($other)) {
            return false;
        }
        $current = $other;
        while (null !== DomRegistry::state($current)->parentId) {
            $parentId = DomRegistry::state($current)->parentId;
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                return false;
            }
            if ($parent->id === $node->id) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    public static function ownerDocumentEntry(ObjectEntry $node): ?ObjectEntry
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            return null;
        }
        if (null === $state->documentId) {
            return null;
        }

        return DomRegistry::entry($state->documentId);
    }

    public static function readNodeValue(ObjectEntry $node): ?string
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            return null;
        }
        // Text / CDATA / Comment / PI / Attr / Namespace — php-src dom_node_node_value_read (#19455, #20097).
        if (self::isCharacterData($node)
            || DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType
            || DomConstants::XML_PROCESSING_INSTRUCTION_NODE === $state->nodeType
            || DomConstants::XML_NAMESPACE_DECL_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        // Living Dom\* Element / DocumentFragment — always null (php-src modern node.c; #21054).
        // Legacy DOMElement still concatenates descendant text (libxml / #19455).
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType
            || DomConstants::XML_DOCUMENT_FRAG_NODE === $state->nodeType
        ) {
            if (str_starts_with(strtolower($node->class->name), 'dom\\')) {
                return null;
            }
        }
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType) {
            // Modern Dom\Element::$nodeValue is always null (php-src / RFC; #21034).
            // Legacy DOMElement still returns concatenated child text.
            if (VmDomLiving::isLivingElement($node)) {
                return null;
            }
            $parts = [];
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null === $child) {
                    continue;
                }
                // Comments/PIs do not contribute to Element nodeValue (libxml/php-src).
                if (self::isCommentNode($child)
                    || DomConstants::XML_PROCESSING_INSTRUCTION_NODE === DomRegistry::state($child)->nodeType) {
                    continue;
                }
                $childValue = self::readNodeValue($child);
                if (null !== $childValue && '' !== $childValue) {
                    $parts[] = $childValue;
                }
            }

            return implode('', $parts);
        }

        return null;
    }

    public static function writeNodeValue(Context $ctx, ObjectEntry $node, string $value): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType) {
            // Live Attr handle (php-src dom_attr_value_write / ext/dom/attr.c; #19281, #20501).
            self::syncAttributeNodeValue($ctx, $node, $value, true);
            $ownerElementId = $state->ownerElementId;
            if (null === $ownerElementId) {
                return;
            }
            $owner = DomRegistry::entry($ownerElementId);
            if (null === $owner || !self::isElement($owner)) {
                return;
            }
            $ownerState = DomRegistry::state($owner);
            $name = $state->nodeName;
            $ownerState->attributes[$name] = $value;
            // Bogus Attr named xmlns:* stays in the Attr map; nsDef is separate (#19718).
            // php-src Attr value write does not refresh the document ID table (#19870).
            if (CompilerVersion::supportsDomTokenList() && 'class' === $name) {
                VmDomTokenList::invalidateForElement($owner);
            }
            self::syncElementAttributes($ctx, $owner);

            return;
        }
        // Text / CDATA / Comment — php-src dom_node_node_value_write / characterdata (#19295).
        if (self::isCharacterData($node)) {
            self::writeCharacterDataContent($node, $value);

            return;
        }
        if (DomConstants::XML_ELEMENT_NODE !== $state->nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $state->nodeType
        ) {
            return;
        }
        // php-src dom_node_textContent_write / node_value_write — unlink all children before
        // inserting replacement text (#20646). Clearing childIds alone left held element
        // wrappers still parented (live-tree desync vs Zend).
        // Empty string still inserts one empty DOMText (DOM Living / php-src; #22657).
        self::removeAllLiveStandardChildren($ctx, $node, true);
        $ownerDoc = self::ownerDocumentEntry($node);
        $text = self::createTextNode($ctx, $value, $ownerDoc);
        $state->childIds[] = $text->id;
        self::linkChildToParent($text, $node);
        self::syncSubtree($ctx, $node);
        // Live SimpleXML peer — same libxml node in php-src (#20137).
        VmDomSimpleXmlBridge::syncSimpleXmlTextFromDom($node, $value);
    }

    public static function readTextContent(ObjectEntry $node): string
    {
        if (!DomRegistry::has($node)) {
            return '';
        }
        $state = DomRegistry::state($node);
        // CharacterData + PI — php-src dom_node_text_content_read (#19455).
        if (self::isCharacterData($node)
            || DomConstants::XML_PROCESSING_INSTRUCTION_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        if (self::isEntityReference($node)) {
            return $state->entityReplacementText ?? '';
        }
        if (DomConstants::XML_ATTRIBUTE_NODE === $state->nodeType) {
            return $state->textContent ?? '';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            // Comments/PIs are readable on themselves but omitted from parent textContent.
            if (self::isCommentNode($child)
                || DomConstants::XML_PROCESSING_INSTRUCTION_NODE === DomRegistry::state($child)->nodeType) {
                continue;
            }
            $parts[] = self::readTextContent($child);
        }

        return implode('', $parts);
    }

    public static function writeTextContent(Context $ctx, ObjectEntry $node, string $value): void
    {
        self::writeNodeValue($ctx, $node, $value);
    }

    public static function isElement(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_ELEMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    /**
     * True when {@see DOMNode::$childNodes} is a live collection property (php-src node.c; #26330).
     * Excludes list/map/token/xpath handles that also sit in DomRegistry.
     */
    public static function exposesChildNodes(ObjectEntry $entry): bool
    {
        if (!DomRegistry::has($entry)) {
            return false;
        }
        $nodeType = DomRegistry::state($entry)->nodeType;

        return $nodeType >= DomConstants::XML_ELEMENT_NODE
            && $nodeType <= DomConstants::XML_NAMESPACE_DECL_NODE;
    }

    public static function isTextNode(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_TEXT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isCdataNode(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_CDATA_SECTION_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isTextOrCdataNode(ObjectEntry $entry): bool
    {
        return self::isTextNode($entry) || self::isCdataNode($entry);
    }

    public static function isCommentNode(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_COMMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isCharacterData(ObjectEntry $entry): bool
    {
        return self::isTextOrCdataNode($entry) || self::isCommentNode($entry);
    }

    public static function isEntityReference(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_ENTITY_REF_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isEntity(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_ENTITY_DECL_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isNotation(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_NOTATION_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isAttr(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_ATTRIBUTE_NODE === DomRegistry::state($entry)->nodeType;
    }

    /**
     * Wrong-document + hierarchy type guards shared by appendChild/insertBefore/replaceChild (#30271).
     * Validation only — does not mutate the tree (thin-AOT LiveSlots still owns linking).
     */
    public static function assertCanReceiveTreeMutationChild(ObjectEntry $parent, ObjectEntry $child): void
    {
        if (self::isDocumentFragment($child)) {
            if (self::isAttr($parent)) {
                DomExceptionConstants::raiseHierarchyRequest();
            }

            return;
        }
        if (self::isAttr($child)) {
            if (!self::isElement($parent)) {
                throw new \DOMException('Hierarchy request error');
            }
            self::assertSameDocument($parent, $child);

            return;
        }
        // php-src ext/dom/node.c — WRONG_DOCUMENT_ERR before hierarchy type rejection (#30271).
        self::assertSameDocument($parent, $child);
        if (!self::isTreeMutationChild($child)) {
            DomExceptionConstants::raiseHierarchyRequest();
        }
        self::assertNotAncestorOfParent($parent, $child);
    }

    public static function isDocument(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    /** DOMImplementation or Dom\Implementation (#20898). */
    public static function isImplementation(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);

        return self::CLASS_IMPLEMENTATION === $lc
            || VmDomLiving::CLASS_IMPLEMENTATION === $lc;
    }

    public static function isDocumentFragment(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_FRAG_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isAppendableNode(ObjectEntry $entry): bool
    {
        return self::isTreeMutationChild($entry)
            || self::isDocumentFragment($entry)
            || self::isAttr($entry);
    }

    public static function isAppendChildCandidate(ObjectEntry $entry): bool
    {
        return self::isTreeMutationChild($entry)
            || self::isDocumentFragment($entry)
            || self::isAttr($entry);
    }

    private static function isTreeMutationChild(ObjectEntry $entry): bool
    {
        return self::isElement($entry)
            || self::isTextOrCdataNode($entry)
            || self::isCommentNode($entry)
            || self::isEntityReference($entry)
            || self::isProcessingInstruction($entry);
    }

    /**
     * Install DOMAttr on an Element via the attribute map (php-src ext/dom/node.c; #19445).
     * Not a childNodes entry — same observable as setAttributeNode() for the installed Attr.
     */
    private static function appendOrInsertAttribute(
        Context $ctx,
        ObjectEntry $parent,
        ObjectEntry $attr
    ): ObjectEntry {
        if (!self::isElement($parent)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $attr);
        self::setAttributeNode($ctx, $parent, $attr);

        return $attr;
    }

    /**
     * Attr appendChild — Text + EntityReference only (php-src ext/dom/node.c; #24512).
     */
    private static function appendChildToAttribute(
        Context $ctx,
        ObjectEntry $attr,
        ObjectEntry $child
    ): ObjectEntry {
        self::assertAttrMutationChild($attr, $child);
        self::assertSameDocument($attr, $child);
        self::assertNotAncestorOfParent($attr, $child);
        self::detachNodeIfAttached($ctx, $child);
        DomRegistry::state($attr)->childIds[] = $child->id;
        self::linkChildToParent($child, $attr);
        self::syncSubtree($ctx, $attr);
        self::refreshAttrValueFromChildren($ctx, $attr);

        return $child;
    }

    /**
     * Legacy DOM Attr children: XML_TEXT_NODE | XML_ENTITY_REF_NODE only (#24512).
     */
    private static function assertAttrMutationChild(ObjectEntry $parent, ObjectEntry $child): void
    {
        if (!self::isAttr($parent)) {
            return;
        }
        if (!self::isTextNode($child) && !self::isEntityReference($child)) {
            DomExceptionConstants::raiseHierarchyRequest();
        }
    }

    /**
     * Recompute Attr::$value from text children without collapsing the child list
     * (unlike {@see syncAttributeNodeValue} / ensureAttrValueTextChild; #24512).
     * EntityReference children contribute empty string to the expanded value (Zend).
     */
    private static function refreshAttrValueFromChildren(Context $ctx, ObjectEntry $attr): void
    {
        if (!self::isAttr($attr)) {
            return;
        }
        $attrState = DomRegistry::state($attr);
        $parts = [];
        foreach ($attrState->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !self::isTextNode($child)) {
                continue;
            }
            $parts[] = DomRegistry::state($child)->textContent ?? '';
        }
        $value = implode('', $parts);
        $attrState->textContent = $value;
        if ($attr->hasProperty(self::PROP_VALUE)) {
            $attr->getProperty(self::PROP_VALUE)->string($value);
        }
        if ($attr->hasProperty(self::PROP_NODE_VALUE)) {
            $attr->getProperty(self::PROP_NODE_VALUE)->string($value);
        }
        $ownerElementId = $attrState->ownerElementId;
        if (null === $ownerElementId) {
            return;
        }
        $owner = DomRegistry::entry($ownerElementId);
        if (null === $owner || !self::isElement($owner)) {
            return;
        }
        $ownerState = DomRegistry::state($owner);
        $name = $attrState->nodeName;
        $ownerState->attributes[$name] = $value;
        if (CompilerVersion::supportsDomTokenList() && 'class' === $name) {
            VmDomTokenList::invalidateForElement($owner);
        }
        self::syncElementAttributes($ctx, $owner);
    }

    private static function appendDocumentChild(Context $ctx, ObjectEntry $document, ObjectEntry $child): void
    {
        self::assertSameDocument($document, $child);
        self::detachNodeIfAttached($ctx, $child);
        $parentState = DomRegistry::state($document);
        $existing = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_NULL !== $existing->type) {
            $parentState->childIds[] = $child->id;
            self::linkChildToParent($child, $document);
            self::registerSubtreeElementIdsIfConnected($child);

            return;
        }
        if (self::isElement($child)) {
            $parentState->childIds[] = $child->id;
            $parentState->documentElementName = DomRegistry::state($child)->nodeName;
            $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($child);
            self::linkChildToParent($child, $document);
            self::propagateDocumentId($child, $document->id);
            self::registerSubtreeElementIdsIfConnected($child);

            return;
        }
        $parentState->childIds[] = $child->id;
        self::linkChildToParent($child, $document);
        self::propagateDocumentId($child, $document->id);
        self::registerSubtreeElementIdsIfConnected($child);
    }

    /** NodeList::item via {@see PROP_CHILD_NODES_OWNER} walk (#27410). */
    public static function nodeListItemViaChildNodesOwner(ObjectEntry $nodeList, int $index): ?ObjectEntry
    {
        if ($index < 0 || !$nodeList->hasProperty(self::PROP_CHILD_NODES_OWNER)) {
            return null;
        }
        $ownerVar = $nodeList->getProperty(self::PROP_CHILD_NODES_OWNER)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $ownerVar->type) {
            return null;
        }
        $owner = $ownerVar->toObject();
        if (!$owner->hasProperty(self::PROP_FIRST_CHILD)) {
            return null;
        }
        $curVar = $owner->getProperty(self::PROP_FIRST_CHILD)->resolveIndirect();
        $pos = 0;
        while (Variable::TYPE_OBJECT === $curVar->type) {
            $cur = $curVar->toObject();
            if ($pos === $index) {
                return $cur;
            }
            if (!$cur->hasProperty(self::PROP_NEXT_SIBLING)) {
                return null;
            }
            $curVar = $cur->getProperty(self::PROP_NEXT_SIBLING)->resolveIndirect();
            ++$pos;
        }

        return null;
    }

    /**
     * Nodes php-src can clone via xmlDocCopyNode (ext/dom/node.c dom_node_clone_node).
     * Documents/doctypes stay unsupported in this build.
     */
    public static function isCloneableNode(ObjectEntry $entry): bool
    {
        return self::isElement($entry)
            || self::isDocumentFragment($entry)
            || self::isTextOrCdataNode($entry)
            || self::isCommentNode($entry)
            || self::isProcessingInstruction($entry)
            || self::isEntityReference($entry)
            || self::isAttr($entry);
    }

    public static function cloneNode(Context $ctx, ObjectEntry $source, bool $deep): Variable
    {
        if (!self::isCloneableNode($source)) {
            throw new \TypeError('DOMNode::cloneNode() must be called on a DOMNode instance');
        }

        $cloned = self::cloneNodeEntry($ctx, $source, $deep);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($cloned);

        return $var;
    }

    public static function importNode(Context $ctx, ObjectEntry $document, ObjectEntry $node, bool $deep): Variable
    {
        self::ensureDocument($document);
        $living = VmDomLiving::isLivingDocument($document);
        if ($living) {
            // php-src Dom\Document::importNode — Dom\Node only (#20940).
            if (!VmDomLiving::isLivingNodeInstance($node, $ctx)) {
                throw new \TypeError(
                    'Dom\\Document::importNode(): Argument #1 ($node) must be of type Dom\\Node'
                );
            }
        } elseif (!self::isDomNode($node)) {
            throw new \TypeError('DOMDocument::importNode(): Argument #1 ($importedNode) must be of type DOMNode');
        }
        $imported = self::importNodeEntry($ctx, $document, $node, $deep);
        // libxml xmlDOMWrapCloneNode — materialize ancestor-scoped xmlns on import root (#21482).
        self::reconcileImportedSubtreeNamespaceDeclarations($imported);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($imported);

        return $var;
    }

    /**
     * Dom\Document::importLegacyNode() — import legacy DOMNode into living document
     * (php-src ext/dom/document.c Dom_Document_importLegacyNode; #20940).
     */
    public static function importLegacyNode(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $node,
        bool $deep
    ): Variable {
        self::ensureDocument($document);
        if (!VmDomLiving::isLivingDocument($document)) {
            throw new \LogicException(
                'importLegacyNode() is only defined on Dom\\Document in this compiler build'
            );
        }
        if (!VmDomLiving::isLegacyDomNodeInstance($node, $ctx)) {
            throw new \TypeError(
                'Dom\\Document::importLegacyNode(): Argument #1 ($node) must be of type DOMNode'
            );
        }
        $imported = self::importNodeEntry($ctx, $document, $node, $deep);
        self::reconcileImportedSubtreeNamespaceDeclarations($imported);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($imported);

        return $var;
    }

    /**
     * DOMDocument::adoptNode() — move node into this document (php-src ext/dom/document.c; #19654, #24995).
     *
     * Unlinks from the previous parent / owner element and updates ownerDocument. Does not
     * insert into the target tree (caller must appendChild / etc.).
     *
     * On reference / PROFILE&lt;8.3 matches Zend 8.2: method exists but throws
     * {@code Error: Not yet implemented} ({@see CompilerVersion::supportsDomDocumentAdoptNode()}).
     */
    public static function adoptNode(Context $ctx, ObjectEntry $document, ObjectEntry $node): Variable
    {
        if (!CompilerVersion::supportsDomDocumentAdoptNode()) {
            throw new \Error('Not yet implemented');
        }
        self::ensureDocument($document);
        if (!self::isDomNode($node)) {
            throw new \TypeError('DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode');
        }
        if (self::isDocument($node)
            || self::isDocumentType($node)
            || self::isEntity($node)
            || self::isNotation($node)
        ) {
            return self::rejectAdoptUnsupportedNode($node, $document);
        }

        if (self::isAttr($node)) {
            self::unlinkAttributeFromOwnerElement($ctx, $node);
        } else {
            self::detachNodeIfAttached($ctx, $node);
        }

        $oldDocumentId = DomRegistry::state($node)->documentId;
        if ($oldDocumentId !== $document->id) {
            self::propagateDocumentId($node, $document->id);
            self::syncSubtree($ctx, $node);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($node);

        return $var;
    }

    /** php-src php_dom_throw_error(NOT_SUPPORTED_ERR) + RETURN_FALSE on legacy non-strict. */
    private static function rejectAdoptUnsupportedNode(ObjectEntry $node, ObjectEntry $targetDocument): Variable
    {
        $strictSource = self::isDocument($node) ? $node : (self::ownerDocumentEntry($node) ?? $targetDocument);
        if (self::documentStrictErrorChecking($strictSource)) {
            throw new \DOMException('Not Supported Error', DomExceptionConstants::NOT_SUPPORTED_ERR);
        }
        $var = new Variable();
        $var->bool(false);

        return $var;
    }

    private static function documentStrictErrorChecking(ObjectEntry $document): bool
    {
        if (!$document->hasProperty(self::PROP_STRICT_ERROR_CHECKING)) {
            return true;
        }
        $slot = $document->getProperty(self::PROP_STRICT_ERROR_CHECKING)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $slot->type) {
            return $slot->toBool();
        }

        return true;
    }

    /** Detach Attr from its owner element (php-src xmlUnlinkNode for ATTRIBUTE_NODE). */
    private static function unlinkAttributeFromOwnerElement(Context $ctx, ObjectEntry $attr): void
    {
        $attrState = DomRegistry::state($attr);
        $prevOwnerId = $attrState->ownerElementId;
        if (null === $prevOwnerId) {
            return;
        }
        $prevOwner = DomRegistry::entry($prevOwnerId);
        if (null === $prevOwner || !self::isElement($prevOwner)) {
            self::detachAttributeNode($attr);

            return;
        }
        $prevState = DomRegistry::state($prevOwner);
        foreach ($prevState->attributeNodeIds as $qName => $cachedId) {
            if ($cachedId !== $attr->id) {
                continue;
            }
            $previousIdValue = $prevState->attributes[$qName] ?? null;
            $idBearing = self::elementAttributeIsIdBearing($prevOwner, $qName);
            unset(
                $prevState->attributes[$qName],
                $prevState->attributeNamespaces[$qName],
                $prevState->attributeNodeIds[$qName]
            );
            self::rebindElementIdOnAttributeWrite($prevOwner, $qName, $previousIdValue, false, $idBearing);
            self::syncElementAttributes($ctx, $prevOwner);
            break;
        }
        self::detachAttributeNode($attr);
    }

    private static function importNodeEntry(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $node,
        bool $deep
    ): ObjectEntry {
        if (self::isTextOrCdataNode($node)) {
            $state = DomRegistry::state($node);
            if (self::isCdataNode($node)) {
                $text = self::createCdataSection($ctx, $state->textContent ?? '', $document);
            } else {
                $text = self::createTextNode($ctx, $state->textContent ?? '', $document);
            }
            self::linkChildToParent($text, null);

            return $text;
        }
        // Comment / PI / EntityReference — mirror cloneNodeEntry (php-src xmlDocCopyNode; #20157).
        if (self::isCommentNode($node)) {
            $state = DomRegistry::state($node);
            $comment = self::createComment($ctx, $state->textContent ?? '', $document);
            self::linkChildToParent($comment, null);

            return $comment;
        }
        if (self::isProcessingInstruction($node)) {
            $state = DomRegistry::state($node);
            $pi = self::createProcessingInstruction(
                $ctx,
                $state->nodeName,
                $state->textContent ?? '',
                $document
            );
            self::linkChildToParent($pi, null);

            return $pi;
        }
        if (self::isEntityReference($node)) {
            $state = DomRegistry::state($node);
            $eref = self::createEntityReference($ctx, $state->nodeName, $document)->toObject();
            self::linkChildToParent($eref, null);

            return $eref;
        }
        if (self::isAttr($node)) {
            $state = DomRegistry::state($node);
            $attr = self::createAttributeNS($ctx, $state->namespaceUri, $state->nodeName, $document)->toObject();
            self::syncAttributeNodeValue(
                $ctx,
                $attr,
                $state->textContent ?? '',
                [] !== $state->childIds
            );
            self::linkChildToParent($attr, null);

            return $attr;
        }
        if (self::isElement($node)) {
            $sourceState = DomRegistry::state($node);
            // Pass $document so living Dom\* nodeClassMap → Dom\Element / Dom\HTMLElement (#20940).
            $imported = self::createElement($ctx, $sourceState->nodeName, $document)->toObject();
            self::linkChildToParent($imported, null);
            $importedState = DomRegistry::state($imported);
            $importedState->documentId = $document->id;
            $importedState->attributes = $sourceState->attributes;
            $importedState->attributeNamespaces = $sourceState->attributeNamespaces;
            $importedState->namespaceDeclarations = $sourceState->namespaceDeclarations;
            $importedState->xmlnsAttributePrefixes = $sourceState->xmlnsAttributePrefixes;
            $importedState->localName = $sourceState->localName;
            $importedState->prefix = $sourceState->prefix;
            $importedState->namespaceUri = $sourceState->namespaceUri;
            // libxml xmlCopyProp copies XML_ATTRIBUTE_ID (HTML id / xml:id / DTD ATTLIST ID);
            // setIdAttribute does not survive importNode on Zend (#20830, #21102, re-#19212).
            $importedState->attributeIsId = $sourceState->attributeIsId;
            $sourceDocument = self::ownerDocumentEntry($node);
            if (null !== $sourceDocument) {
                self::stampCopyableLibxmlIdTypesFromDocument(
                    $sourceDocument,
                    $sourceState,
                    $importedState
                );
            }
            $importedState->idAttributeName = null;
            if ($deep) {
                foreach ($sourceState->childIds as $childId) {
                    $child = DomRegistry::entry($childId);
                    if (null === $child) {
                        continue;
                    }
                    $importedChild = self::importNodeEntry($ctx, $document, $child, true);
                    $importedState->childIds[] = $importedChild->id;
                    self::linkChildToParent($importedChild, $imported);
                }
            }
            self::syncSubtree($ctx, $imported);
            self::propagateDocumentId($imported, $document->id);

            return $imported;
        }
        if (self::isDocumentFragment($node)) {
            $imported = self::createDocumentFragment($ctx, $document)->toObject();
            self::linkChildToParent($imported, null);
            $importedState = DomRegistry::state($imported);
            $importedState->documentId = $document->id;
            if ($deep) {
                $sourceState = DomRegistry::state($node);
                foreach ($sourceState->childIds as $childId) {
                    $child = DomRegistry::entry($childId);
                    if (null === $child) {
                        continue;
                    }
                    $importedChild = self::importNodeEntry($ctx, $document, $child, true);
                    $importedState->childIds[] = $importedChild->id;
                    self::linkChildToParent($importedChild, $imported);
                }
            }
            self::syncSubtree($ctx, $imported);
            self::propagateDocumentId($imported, $document->id);

            return $imported;
        }

        throw new \DOMException('Not supported importNode for this node type in this compiler build');
    }

    private static function cloneNodeEntry(Context $ctx, ObjectEntry $source, bool $deep): ObjectEntry
    {
        $sourceState = DomRegistry::state($source);
        $ownerDocument = self::ownerDocumentEntry($source);

        // Character data / leaf nodes — copy content; deep has no effect (php-src xmlDocCopyNode).
        if (self::isTextOrCdataNode($source)) {
            if (self::isCdataNode($source)) {
                $cloned = self::createCdataSection($ctx, $sourceState->textContent ?? '', $ownerDocument);
            } else {
                $cloned = self::createTextNode($ctx, $sourceState->textContent ?? '', $ownerDocument);
            }
            self::linkChildToParent($cloned, null);

            return $cloned;
        }
        if (self::isCommentNode($source)) {
            $cloned = self::createComment($ctx, $sourceState->textContent ?? '', $ownerDocument);
            self::linkChildToParent($cloned, null);

            return $cloned;
        }
        if (self::isProcessingInstruction($source)) {
            if (null === $ownerDocument) {
                throw new \DOMException('Not supported cloneNode for this node type in this compiler build');
            }
            $cloned = self::createProcessingInstruction(
                $ctx,
                $sourceState->nodeName,
                $sourceState->textContent ?? '',
                $ownerDocument
            );
            self::linkChildToParent($cloned, null);

            return $cloned;
        }
        if (self::isEntityReference($source)) {
            $cloned = self::createEntityReference($ctx, $sourceState->nodeName, $ownerDocument)->toObject();
            self::linkChildToParent($cloned, null);

            return $cloned;
        }
        if (self::isAttr($source)) {
            $cloned = self::createAttributeNS($ctx, $sourceState->namespaceUri, $sourceState->nodeName, $ownerDocument)->toObject();
            self::syncAttributeNodeValue(
                $ctx,
                $cloned,
                $sourceState->textContent ?? '',
                [] !== $sourceState->childIds
            );
            self::linkChildToParent($cloned, null);

            return $cloned;
        }

        if (self::isElement($source)) {
            $cloned = self::createElement($ctx, $sourceState->nodeName)->toObject();
        } elseif (self::isDocumentFragment($source)) {
            $cloned = self::createDocumentFragment($ctx, $ownerDocument)->toObject();
        } else {
            throw new \DOMException('Not supported cloneNode for this node type in this compiler build');
        }

        self::linkChildToParent($cloned, null);
        $clonedState = DomRegistry::state($cloned);
        $clonedState->documentId = $sourceState->documentId;
        if (self::isElement($source)) {
            $clonedState->attributes = $sourceState->attributes;
            $clonedState->attributeNamespaces = $sourceState->attributeNamespaces;
            $clonedState->namespaceDeclarations = $sourceState->namespaceDeclarations;
            $clonedState->xmlnsAttributePrefixes = $sourceState->xmlnsAttributePrefixes;
            $clonedState->localName = $sourceState->localName;
            $clonedState->prefix = $sourceState->prefix;
            $clonedState->namespaceUri = $sourceState->namespaceUri;
            $clonedState->attributeIsId = $sourceState->attributeIsId;
            $clonedState->idAttributeName = $sourceState->idAttributeName;
        }
        if ($deep) {
            foreach ($sourceState->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null === $child || !self::isCloneableNode($child)) {
                    continue;
                }
                $clonedChild = self::cloneNodeEntry($ctx, $child, true);
                $clonedState->childIds[] = $clonedChild->id;
                self::linkChildToParent($clonedChild, $cloned);
            }
        }
        self::syncSubtree($ctx, $cloned);

        return $cloned;
    }

    private static function documentDoctypeInternalSubset(DomNodeState $docState): ?string
    {
        if (null === $docState->doctypeId) {
            return null;
        }
        $doctype = DomRegistry::entry($docState->doctypeId);
        if (null === $doctype) {
            return null;
        }

        return DomRegistry::state($doctype)->internalSubset;
    }

    private static function serializeDoctype(
        string $name,
        string $publicId,
        string $systemId,
        ?string $internalSubset = null
    ): string {
        $escapedName = self::escapeName($name);
        if ('' !== $publicId) {
            $out = sprintf(
                '<!DOCTYPE %s PUBLIC "%s" "%s"',
                $escapedName,
                self::escapeAttr($publicId),
                self::escapeAttr($systemId)
            );
        } elseif ('' !== $systemId) {
            $out = sprintf(
                '<!DOCTYPE %s SYSTEM "%s"',
                $escapedName,
                self::escapeAttr($systemId)
            );
        } else {
            $out = '<!DOCTYPE '.$escapedName;
        }

        if (null !== $internalSubset && '' !== $internalSubset) {
            $out .= " [\n".$internalSubset.']';
        }

        return $out.'>';
    }

    private static function escapeAttr(string $value): string
    {
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }

    private static function escapeText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    /**
     * HTML text escape matching libxml htmlDocDump: &/</> plus named HTML 4.01 entity references
     * for non-ASCII codepoints (e.g. \xC2\xA0 → &nbsp;, \xC3\xA9 → &eacute;), with decimal
     * NCRs (&#128512;) for codepoints that have no HTML 4.01 name (#25547).
     *
     * Used for HTML document-wide saveHTML() only. Node-scoped saveHTML($node) on HTML docs
     * uses escapeText (libxml htmlNodeDump keeps UTF-8; #24152). php-src ext/dom/document.c;
     * PHP's htmlentities(…, ENT_HTML401) covers the named table (#23684) but leaves unnamed
     * Unicode as UTF-8 — libxml uses decimal &#N; instead.
     */
    private static function escapeHtmlText(string $value): string
    {
        return self::encodeNonAsciiHtmlDecimal(
            htmlentities($value, ENT_NOQUOTES | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8', true)
        );
    }

    /**
     * Text escape for saveHTML / innerHTML by document mode (#24152 / #23684 / #25208).
     */
    private static function escapeHtmlTextForMode(string $value, int $mode): string
    {
        return match ($mode) {
            self::HTML_ESCAPE_NAMED => self::escapeHtmlText($value),
            self::HTML_ESCAPE_NUMERIC => self::escapeHtmlNumericText($value),
            default => self::escapeText($value),
        };
    }

    /**
     * Attribute escape for HTML serializer modes.
     * NUMERIC → &#xHH; (#25208); NAMED → HTML 4.01 named entities (#23684 / #25246);
     * UTF8 → escapeAttr (UTF-8 non-ASCII; #24152 node dumps).
     */
    private static function escapeHtmlAttrForMode(string $value, int $mode): string
    {
        return match ($mode) {
            self::HTML_ESCAPE_NUMERIC => self::escapeHtmlNumericAttr($value),
            self::HTML_ESCAPE_NAMED => self::escapeHtmlNamedAttr($value),
            default => self::escapeAttr($value),
        };
    }

    /**
     * Document-wide saveHTML attribute escape: &/" plus HTML 4.01 named entities for
     * non-ASCII, with decimal NCRs for unnamed codepoints (libxml htmlDocDump; #25246 / #25547).
     * ENT_COMPAT matches double-quoted attrs.
     */
    private static function escapeHtmlNamedAttr(string $value): string
    {
        return self::encodeNonAsciiHtmlDecimal(
            htmlentities($value, ENT_COMPAT | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8', true)
        );
    }

    /**
     * libxml HTML dump on XML documents: &<> plus &#xHH; for non-ASCII (htmlNodeDump; #25208).
     */
    private static function escapeHtmlNumericText(string $value): string
    {
        return self::encodeNonAsciiHtmlNumeric(self::escapeText($value));
    }

    /**
     * libxml HTML dump on XML documents: &/" plus &#xHH; for non-ASCII attribute values (#25208).
     */
    private static function escapeHtmlNumericAttr(string $value): string
    {
        return self::encodeNonAsciiHtmlNumeric(self::escapeAttr($value));
    }

    /**
     * After htmlentities(ENT_HTML401): map leftover non-ASCII UTF-8 to decimal &#N;
     * (libxml htmlDocDump for emoji/CJK/etc.; #25547). Named entities already in the
     * string are ASCII (&…;) and are left unchanged.
     */
    private static function encodeNonAsciiHtmlDecimal(string $value): string
    {
        return self::encodeNonAsciiHtmlRefs($value, false);
    }

    /**
     * Map non-ASCII UTF-8 codepoints to uppercase hex character references (&#xE9;).
     * ASCII bytes (including already-escaped &amp;/&lt;/&gt;/&quot;) are left unchanged.
     */
    private static function encodeNonAsciiHtmlNumeric(string $value): string
    {
        return self::encodeNonAsciiHtmlRefs($value, true);
    }

    /**
     * Walk UTF-8 and replace non-ASCII codepoints with character references.
     *
     * @param bool $hex true → &#xHH; (unencoded XML saveHTML); false → &#N; (HTML named dump)
     */
    private static function encodeNonAsciiHtmlRefs(string $value, bool $hex): string
    {
        $out = '';
        $len = \strlen($value);
        $i = 0;
        while ($i < $len) {
            $o = \ord($value[$i]);
            if ($o < 0x80) {
                $out .= $value[$i];
                ++$i;
                continue;
            }
            if (0xC0 === ($o & 0xE0) && $i + 1 < $len) {
                $cp = (($o & 0x1F) << 6) | (\ord($value[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (0xE0 === ($o & 0xF0) && $i + 2 < $len) {
                $cp = (($o & 0x0F) << 12)
                    | ((\ord($value[$i + 1]) & 0x3F) << 6)
                    | (\ord($value[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (0xF0 === ($o & 0xF8) && $i + 3 < $len) {
                $cp = (($o & 0x07) << 18)
                    | ((\ord($value[$i + 1]) & 0x3F) << 12)
                    | ((\ord($value[$i + 2]) & 0x3F) << 6)
                    | (\ord($value[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $out .= $value[$i];
                ++$i;
                continue;
            }
            $out .= $hex
                ? '&#x'.\strtoupper(\dechex($cp)).';'
                : '&#'.$cp.';';
        }

        return $out;
    }

    private static function escapeName(string $name): string
    {
        return $name;
    }

    /** @throws \DOMException when $name is not a valid XML entity reference name (php-src document.c). */
    private static function assertValidEntityReferenceName(string $name): void
    {
        if ('' === $name || !preg_match('/^[A-Za-z_][\w.-]*$/', $name)) {
            throw new \DOMException('Invalid Character Error');
        }
    }

    /** @throws \DOMException when $name is not a valid XML Name (php-src xmlValidateName). */
    private static function assertValidXmlName(string $name): void
    {
        if (!self::isValidXmlName($name)) {
            throw new \DOMException('Invalid Character Error', DomExceptionConstants::INVALID_CHARACTER_ERR);
        }
    }

    /** libxml xmlValidateName — Name production (allows leading ':'). */
    private static function isValidXmlName(string $name): bool
    {
        return '' !== $name && 1 === preg_match('/^[A-Za-z_:][\w.:-]*$/', $name);
    }

    private static function falseBoolVariable(): Variable
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool(false);

        return $var;
    }

    /**
     * php-src php_dom_throw_error — strict document → DOMException; else E_WARNING (#24804).
     * Caller returns false / no-ops after a non-strict warning.
     */
    private static function phpDomThrowError(
        Context $ctx,
        ?ObjectEntry $document,
        int $code,
        string $message,
        string $methodPrefix
    ): void {
        $strict = true;
        if (null !== $document && self::isDocument($document)) {
            $strict = self::documentStrictErrorChecking($document);
        }
        if ($strict) {
            throw new \DOMException($message, $code);
        }
        $ctx->errors->triggerError(
            $methodPrefix.': '.$message,
            ErrorReporter::E_WARNING,
            null,
            $ctx
        );
    }

    /**
     * Dom\Element::rename() — update QName + namespaceURI
     * (php-src ext/dom/element.c PHP_METHOD(Dom_Element, rename); #20924).
     *
     * @throws \DOMException
     */
    public static function renameElement(ObjectEntry $element, ?string $namespaceUri, string $qualifiedName): void
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        self::assertValidElementNSName($namespaceUri, $qualifiedName);
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);

        $state = DomRegistry::state($element);
        $isCurrentlyHtmlNs = VmDomLiving::HTML_NS === ($state->namespaceUri ?? '')
            || VmDomLiving::CLASS_HTML_ELEMENT === strtolower($element->class->name);
        $willBeHtmlNs = null !== $namespaceUri && VmDomLiving::HTML_NS === $namespaceUri;
        if ($isCurrentlyHtmlNs !== $willBeHtmlNs) {
            if ($isCurrentlyHtmlNs) {
                throw new \DOMException(
                    'It is not possible to move an element out of the HTML namespace because the HTML namespace is tied to the HTMLElement class',
                    DomExceptionConstants::INVALID_MODIFICATION_ERR
                );
            }
            throw new \DOMException(
                'It is not possible to move an element into the HTML namespace because the HTML namespace is tied to the HTMLElement class',
                DomExceptionConstants::INVALID_MODIFICATION_ERR
            );
        }

        $state->localName = $localName;
        $state->prefix = '' !== $prefix ? $prefix : null;
        $state->namespaceUri = $namespaceUri;
        $state->nodeName = $qualifiedName;
        self::writeElementNameSlots($element, $qualifiedName);
    }

    /**
     * Dom\Attr::rename() — update QName + namespaceURI; remap owner attribute maps
     * (php-src ext/dom/element.c Dom_Element::rename attribute branch; #21083).
     *
     * @throws \DOMException
     */
    public static function renameAttr(
        Context $ctx,
        ObjectEntry $attr,
        ?string $namespaceUri,
        string $qualifiedName
    ): void {
        if (!self::isAttr($attr)) {
            throw new \DOMException('Not an attribute node');
        }
        self::assertValidElementNSName($namespaceUri, $qualifiedName);
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);

        $attrState = DomRegistry::state($attr);
        $oldQName = $attrState->nodeName;
        $nsArg = (null === $namespaceUri || '' === $namespaceUri) ? null : $namespaceUri;

        $owner = null;
        $ownerElementId = $attrState->ownerElementId;
        if (null !== $ownerElementId) {
            $owner = DomRegistry::entry($ownerElementId);
            if (null !== $owner && !self::isElement($owner)) {
                $owner = null;
            }
        }

        if (null !== $owner) {
            $existingQName = self::findAttributeQNameByNsAndLocal($owner, $nsArg, $localName);
            if (null !== $existingQName && $existingQName !== $oldQName) {
                throw new \DOMException(
                    'An attribute with the given name in the given namespace already exists',
                    DomExceptionConstants::INVALID_MODIFICATION_ERR
                );
            }

            $elementState = DomRegistry::state($owner);
            $value = $elementState->attributes[$oldQName] ?? ($attrState->textContent ?? '');
            $idBearing = self::elementAttributeIsIdBearing($owner, $oldQName);
            $previousIdValue = $idBearing ? ($elementState->attributes[$oldQName] ?? null) : null;

            if ($oldQName !== $qualifiedName) {
                unset(
                    $elementState->attributes[$oldQName],
                    $elementState->attributeNamespaces[$oldQName],
                    $elementState->attributeNodeIds[$oldQName]
                );
                if (null !== $elementState->idAttributeName && $oldQName === $elementState->idAttributeName) {
                    $elementState->idAttributeName = $qualifiedName;
                }
                if (isset($elementState->attributeIsId[$oldQName])) {
                    $elementState->attributeIsId[$qualifiedName] = $elementState->attributeIsId[$oldQName];
                    unset($elementState->attributeIsId[$oldQName]);
                }
            }
            $elementState->attributes[$qualifiedName] = $value;
            $elementState->attributeNamespaces[$qualifiedName] = $namespaceUri ?? '';
            $elementState->attributeNodeIds[$qualifiedName] = $attr->id;
            self::rebindElementIdOnAttributeWrite($owner, $qualifiedName, $previousIdValue, true, $idBearing);
        }

        $attrState->localName = $localName;
        $attrState->prefix = '' !== $prefix ? $prefix : null;
        $attrState->namespaceUri = $namespaceUri;
        $attrState->nodeName = $qualifiedName;
        if ($attr->hasProperty(self::PROP_NODE_NAME)) {
            $attr->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        }
        // php-src attr.c: living Attr.name is QName (#26024); legacy DOMAttr.name is local (#19754).
        if ($attr->hasProperty(self::PROP_NAME)) {
            $nameProp = VmDomLiving::isLivingAttr($attr) ? $qualifiedName : $localName;
            $attr->getProperty(self::PROP_NAME)->string($nameProp);
        }

        if (null !== $owner) {
            self::ensureNamespaceDeclarationForPrefixedAttribute($owner, $attrState->prefix, $attrState->namespaceUri);
            self::syncElementAttributes($ctx, $owner);
        }
    }

    /**
     * QName + namespace URI constraints for createElementNS / createAttributeNS
     * (php-src ext/dom/document.c; libxml xmlValidateQName; #20594).
     *
     * @throws \DOMException Namespace Error (14)
     */
    private static function assertValidElementNSName(?string $namespace, string $qualifiedName): void
    {
        $error = self::elementNSNameValidationError($namespace, $qualifiedName);
        if (null !== $error) {
            throw new \DOMException($error, DomExceptionConstants::NAMESPACE_ERR);
        }
    }

    /**
     * @return string|null "Namespace Error" when invalid; null when OK
     */
    private static function elementNSNameValidationError(?string $namespace, string $qualifiedName): ?string
    {
        if (!self::isValidXmlQName($qualifiedName)) {
            return 'Namespace Error';
        }
        [$prefix] = self::splitQualifiedName($qualifiedName);
        $ns = $namespace ?? '';
        if ('' !== $prefix && '' === $ns) {
            return 'Namespace Error';
        }
        if ('xml' === $prefix && 'http://www.w3.org/XML/1998/namespace' !== $ns) {
            return 'Namespace Error';
        }
        if ('xmlns' === $prefix && 'http://www.w3.org/2000/xmlns/' !== $ns) {
            return 'Namespace Error';
        }

        return null;
    }

    /** libxml xmlValidateQName — NCName or NCName:NCName (no empty parts, one colon). */
    private static function isValidXmlQName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        $pos = strpos($name, ':');
        if (false === $pos) {
            return 1 === preg_match('/^[A-Za-z_][\w.-]*$/', $name);
        }
        $prefix = substr($name, 0, $pos);
        $local = substr($name, $pos + 1);
        if ('' === $prefix || '' === $local || false !== strpos($local, ':')) {
            return false;
        }

        return 1 === preg_match('/^[A-Za-z_][\w.-]*$/', $prefix)
            && 1 === preg_match('/^[A-Za-z_][\w.-]*$/', $local);
    }

    /** @throws \DOMException when $target is not a valid PI target (php-src dom_document_create_processing_instruction). */
    private static function assertValidProcessingInstructionTarget(string $target): void
    {
        self::assertValidXmlName($target);
        if (0 === strcasecmp($target, 'xml')) {
            throw new \DOMException('Invalid Character Error', DomExceptionConstants::INVALID_CHARACTER_ERR);
        }
    }

    private static function normalizeToggleAttributeQName(ObjectEntry $element, string $qualifiedName): string
    {
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            return $qualifiedName;
        }
        $docState = DomRegistry::state($document);
        if (!$docState->isHtmlDocument) {
            return $qualifiedName;
        }

        return strtolower($qualifiedName);
    }

    private static function hasAttributeByQName(ObjectEntry $element, string $qualifiedName): bool
    {
        if (self::isXmlnsAttributeName($qualifiedName)) {
            return false;
        }
        $state = DomRegistry::state($element);

        return \array_key_exists($qualifiedName, $state->attributes);
    }

    private static function removeAttributeByQName(Context $ctx, ObjectEntry $element, string $qualifiedName): void
    {
        $state = DomRegistry::state($element);
        if (!\array_key_exists($qualifiedName, $state->attributes)) {
            return;
        }
        $previousIdValue = $state->attributes[$qualifiedName] ?? null;
        $idBearing = self::elementAttributeIsIdBearing($element, $qualifiedName);
        if (isset($state->attributeNodeIds[$qualifiedName])) {
            $cached = DomRegistry::entry($state->attributeNodeIds[$qualifiedName]);
            if (null !== $cached && self::isAttr($cached)) {
                self::detachAttributeNode($cached);
            }
            unset($state->attributeNodeIds[$qualifiedName]);
        }
        unset($state->attributes[$qualifiedName], $state->attributeNamespaces[$qualifiedName]);
        self::rebindElementIdOnAttributeWrite($element, $qualifiedName, $previousIdValue, false, $idBearing);
        self::syncElementAttributes($ctx, $element);
    }

    public static function registerNodeClass(
        Context $ctx,
        ObjectEntry $document,
        string $baseClassName,
        ?string $extendedClassName
    ): void {
        self::ensureDocument($document);
        // Living Dom\* docs accept Dom\Node lineage (php-src document.c modern=true; #26061).
        $living = VmDomLiving::isLivingDocument($document);
        $methodLabel = $living
            ? 'Dom\\Document::registerNodeClass()'
            : 'DOMDocument::registerNodeClass()';
        $nodeBaseLc = $living ? VmDomLiving::CLASS_NODE : self::CLASS_NODE;
        $baseEntry = self::resolveClassByName($ctx, $baseClassName);
        if (null === $baseEntry) {
            throw new \TypeError(sprintf(
                '%s: Argument #1 ($baseClass) must be a valid class name, %s given',
                $methodLabel,
                $baseClassName
            ));
        }
        if (!InterfaceCheck::entryIsInstanceOf($baseEntry, $nodeBaseLc, $ctx)) {
            throw new \TypeError(sprintf(
                '%s: Argument #1 ($baseClass) must be a valid class name, %s given',
                $methodLabel,
                $baseClassName
            ));
        }
        if ($baseEntry->isAbstract) {
            throw new \ValueError($methodLabel.': Argument #1 ($baseClass) must not be an abstract class');
        }
        $baseLc = strtolower($baseEntry->name);
        if (null === $extendedClassName) {
            unset(DomRegistry::state($document)->nodeClassMap[$baseLc]);

            return;
        }
        $extendedEntry = self::resolveClassByName($ctx, $extendedClassName);
        if (null === $extendedEntry) {
            throw new \TypeError(sprintf(
                '%s: Argument #2 ($extendedClass) must be a class name derived from %s or null, %s given',
                $methodLabel,
                $baseEntry->name,
                $extendedClassName
            ));
        }
        if (!InterfaceCheck::entryIsInstanceOf($extendedEntry, $baseLc, $ctx)) {
            throw new \TypeError(sprintf(
                '%s: Argument #2 ($extendedClass) must be a class name derived from %s or null, %s given',
                $methodLabel,
                $baseEntry->name,
                $extendedClassName
            ));
        }
        if ($extendedEntry->isAbstract) {
            throw new \ValueError($methodLabel.': Argument #2 ($extendedClass) must not be an abstract class');
        }
        DomRegistry::state($document)->nodeClassMap[$baseLc] = strtolower($extendedEntry->name);
    }

    public static function resolveNodeClass(
        Context $ctx,
        ?ObjectEntry $ownerDocument,
        string $baseLc
    ): ClassEntry {
        $baseLc = strtolower($baseLc);
        $default = $ctx->classes[$baseLc] ?? null;
        if (null === $default) {
            throw new \LogicException(self::classNameFromLc($baseLc).' is not registered in this compiler build');
        }
        if (null === $ownerDocument || !self::isDocument($ownerDocument)) {
            return $default;
        }
        $map = DomRegistry::state($ownerDocument)->nodeClassMap;
        $extendedLc = $map[$baseLc] ?? null;
        if (null === $extendedLc) {
            return $default;
        }
        // Living docs seed legacy DOM* → Dom\* then user overrides Dom\* (#26061):
        // e.g. domelement → dom\htmlelement → MyH.
        $resolvedLc = $extendedLc;
        for ($guard = 0; $guard < 8; ++$guard) {
            $nextLc = $map[$resolvedLc] ?? null;
            if (null === $nextLc || $nextLc === $resolvedLc) {
                break;
            }
            $resolvedLc = $nextLc;
        }

        return $ctx->classes[$resolvedLc] ?? $ctx->classes[$extendedLc] ?? $default;
    }

    /**
     * Living HTML documents: HTMLElement iff namespace is the HTML ns (php-src dom_get_element_ce; #21030).
     *
     * HTMLDocument's nodeClassMap remaps classic DOMElement → Dom\HTMLElement for the HTML path;
     * foreign namespaces must resolve to Dom\Element (or a Dom\Element registerNodeClass override).
     */
    private static function resolveElementClassForNamespace(
        Context $ctx,
        ?ObjectEntry $ownerDocument,
        ?string $namespaceUri
    ): ClassEntry {
        if (self::isLivingHtmlDocument($ownerDocument) && VmDomLiving::HTML_NS !== $namespaceUri) {
            // HTML_NO_DEFAULT_NS parse: still HTMLElement (null ns), not Dom\Element (#26008).
            if (null === $namespaceUri && DomRegistry::state($ownerDocument)->htmlNoDefaultNs) {
                return self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ELEMENT);
            }
            $state = DomRegistry::state($ownerDocument);
            $extendedLc = $state->nodeClassMap[VmDomLiving::CLASS_ELEMENT] ?? null;
            if (null !== $extendedLc && isset($ctx->classes[$extendedLc])) {
                return $ctx->classes[$extendedLc];
            }
            $living = $ctx->classes[VmDomLiving::CLASS_ELEMENT] ?? null;
            if (null !== $living) {
                return $living;
            }
        }

        return self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ELEMENT);
    }

    private static function isLivingHtmlDocument(?ObjectEntry $ownerDocument): bool
    {
        if (null === $ownerDocument || !self::isDocument($ownerDocument)) {
            return false;
        }

        return VmDomLiving::CLASS_HTML_DOCUMENT === strtolower($ownerDocument->class->name);
    }

    /** True when the element's owner document is living Dom\HTMLDocument (#31304). */
    private static function elementOwnerIsLivingHtmlDocument(ObjectEntry $element): bool
    {
        return self::isLivingHtmlDocument(self::ownerDocumentEntry($element));
    }

    /**
     * HTML `<template>` in the XHTML namespace (php-src html5_parser.c; #26034).
     *
     * HTML_NO_DEFAULT_NS parses leave namespaceUri null — those templates keep ordinary children.
     */
    public static function isHtmlTemplateElement(ObjectEntry $element): bool
    {
        if (!self::isElement($element)) {
            return false;
        }
        $state = DomRegistry::state($element);
        $name = strtolower($state->localName ?? $state->nodeName);
        $colon = strrpos($name, ':');
        if (false !== $colon) {
            $name = substr($name, $colon + 1);
        }
        if ('template' !== $name) {
            return false;
        }

        return VmDomLiving::HTML_NS === ($state->namespaceUri ?? null);
    }

    /**
     * Child ids to serialize for an element — template contents fragment when applicable (#26034).
     *
     * @return list<int>
     */
    private static function elementSerializationChildIds(ObjectEntry $entry): array
    {
        $state = DomRegistry::state($entry);
        if (!self::isHtmlTemplateElement($entry)) {
            return $state->childIds;
        }
        $contentId = $state->templateContentId;
        if (null === $contentId) {
            return [];
        }
        $content = DomRegistry::entry($contentId);
        if (null === $content) {
            return [];
        }

        return DomRegistry::state($content)->childIds;
    }

    /**
     * Ensure the DocumentFragment that holds HTML template contents (php-src private_data.c; #26034).
     */
    private static function ensureTemplateContentFragment(Context $ctx, ObjectEntry $template): ObjectEntry
    {
        $state = DomRegistry::state($template);
        if (null !== $state->templateContentId) {
            $existing = DomRegistry::entry($state->templateContentId);
            if (null !== $existing) {
                return $existing;
            }
        }
        $ownerDocument = self::ownerDocumentEntry($template);
        $fragment = self::createDocumentFragment($ctx, $ownerDocument)->toObject();
        // php-src: fragment->parent = template_node (not listed in template children).
        DomRegistry::state($fragment)->parentId = $template->id;
        $state->templateContentId = $fragment->id;

        return $fragment;
    }

    /**
     * Move a template element's ordinary children into its contents fragment after HTML parse (#26034).
     */
    private static function adoptChildrenIntoTemplateContent(Context $ctx, ObjectEntry $template): void
    {
        $state = DomRegistry::state($template);
        $content = self::ensureTemplateContentFragment($ctx, $template);
        if ([] === $state->childIds) {
            return;
        }
        $contentState = DomRegistry::state($content);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            self::linkChildToParent($child, $content);
            $contentState->childIds[] = $childId;
        }
        $state->childIds = [];
        self::syncSubtree($ctx, $template);
        self::syncSubtree($ctx, $content);
    }

    private static function resolveClassByName(Context $ctx, string $name): ?ClassEntry
    {
        $lc = strtolower(ltrim($name, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($name);
        }

        return $ctx->classes[$lc] ?? null;
    }

    public static function requireReceiver(
        Variable $var,
        string $classLc,
        string $label,
        ?Context $ctx = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (null !== $ctx && InterfaceCheck::entryIsInstanceOf($object->class, $classLc, $ctx)) {
            return $object;
        }
        if ($classLc !== strtolower($object->class->name)) {
            // Living Dom\* nodes are parallel to classic DOM* (php-src 8.4); accept by nodeType (#20418).
            if (self::CLASS_ELEMENT === $classLc && self::isElement($object)) {
                return $object;
            }
            if (self::CLASS_NODE === $classLc && self::isDomNode($object)) {
                return $object;
            }
            // Dom\HTMLDocument / Dom\XMLDocument share DOMDocument method handlers (#20556).
            if (self::CLASS_DOCUMENT === $classLc && self::isDocument($object)) {
                return $object;
            }
            // Living leaf Dom\* nodes share classic DOM* method handlers (#20948).
            if (self::CLASS_ATTR === $classLc && self::isAttr($object)) {
                return $object;
            }
            if (self::CLASS_TEXT === $classLc && self::isTextNode($object)) {
                return $object;
            }
            if (self::CLASS_COMMENT === $classLc && self::isCommentNode($object)) {
                return $object;
            }
            if (self::CLASS_CDATA === $classLc && self::isCdataNode($object)) {
                return $object;
            }
            if (self::CLASS_CHARACTER_DATA === $classLc && self::isCharacterData($object)) {
                return $object;
            }
            if (self::CLASS_PROCESSING_INSTRUCTION === $classLc && self::isProcessingInstruction($object)) {
                return $object;
            }
            if (self::CLASS_ENTITY_REFERENCE === $classLc && self::isEntityReference($object)) {
                return $object;
            }
            if (self::CLASS_DOCUMENT_FRAGMENT === $classLc && self::isDocumentFragment($object)) {
                return $object;
            }
            // Dom\NamedNodeMap + Dom\DtdNamedNodeMap share DOMNamedNodeMap method handlers (#20948, #21014).
            if (self::CLASS_NAMED_NODE_MAP === $classLc && self::isNamedNodeMap($object)) {
                return $object;
            }
            if (VmDomLiving::CLASS_NAMED_NODE_MAP === $classLc && self::isAttrNamedNodeMap($object)) {
                return $object;
            }
            if (VmDomLiving::CLASS_DTD_NAMED_NODE_MAP === $classLc && self::isDtdNamedNodeMap($object)) {
                return $object;
            }
            // Dom\TokenList method handlers (#20512, #28227).
            if ((self::CLASS_TOKEN_LIST === $classLc || VmDomLiving::CLASS_TOKEN_LIST === $classLc)
                && self::isTokenList($object)
            ) {
                return $object;
            }
            // Dom\HTMLCollection / Dom\NodeList share DOMNodeList item/count/iterator handlers (#20709, #20757).
            if (self::CLASS_NODE_LIST === $classLc && self::isNodeList($object)) {
                return $object;
            }
            if (VmDomLiving::CLASS_HTML_COLLECTION === $classLc && self::isHtmlCollection($object)) {
                return $object;
            }
            if (VmDomLiving::CLASS_NODE_LIST === $classLc && self::isNodeList($object)
                && VmDomLiving::CLASS_NODE_LIST === strtolower($object->class->name)
            ) {
                return $object;
            }
            // Dom\XPath shares DOMXPath query/evaluate handlers (#20757).
            if (self::CLASS_XPATH === $classLc && self::isXPath($object)) {
                return $object;
            }
            if (VmDomLiving::CLASS_XPATH === $classLc && self::isXPath($object)) {
                return $object;
            }
            // Dom\Implementation shares DOMImplementation method handlers (#20898).
            if (self::CLASS_IMPLEMENTATION === $classLc && self::isImplementation($object)) {
                return $object;
            }
            throw new \TypeError(sprintf('%s must be called on a %s instance', $label, self::classNameFromLc($classLc)));
        }

        return $object;
    }

    public static function isDocumentType(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_TYPE_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isProcessingInstruction(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_PROCESSING_INSTRUCTION_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function typeLabel(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_FLOAT => 'float',
            default => 'mixed',
        };
    }

    private static function classNameFromLc(string $lc): string
    {
        return match ($lc) {
            self::CLASS_IMPLEMENTATION => 'DOMImplementation',
            VmDomLiving::CLASS_IMPLEMENTATION => 'Dom\\Implementation',
            self::CLASS_DOCUMENT => 'DOMDocument',
            self::CLASS_DOCUMENT_TYPE => 'DOMDocumentType',
            VmDomLiving::CLASS_DOCUMENT_TYPE => 'Dom\\DocumentType',
            self::CLASS_PROCESSING_INSTRUCTION => 'DOMProcessingInstruction',
            self::CLASS_ELEMENT => 'DOMElement',
            self::CLASS_DOCUMENT_FRAGMENT => 'DOMDocumentFragment',
            self::CLASS_ENTITY => 'DOMEntity',
            self::CLASS_NOTATION => 'DOMNotation',
            default => $lc,
        };
    }

    /** DOMDocument::normalizeDocument() — normalize entire tree (php-src ext/dom/document.c; #14370). */
    public static function normalizeDocument(Context $ctx, ObjectEntry $document): void
    {
        self::ensureDocument($document);
        self::normalizeLiveStandard($ctx, $document);
    }

    /**
     * DOMDocument::xinclude() — XInclude substitution (php-src ext/dom/document.c; #14370, #20403).
     *
     * PHP-in-PHP walk of {@code xi:include} nodes (text/xml parse + fallback). Return mirrors
     * libxml {@code xmlXIncludeProcessFlags}: {@code false} when zero substitutions, positive
     * count on success, {@code -1} when any include failed without fallback.
     *
     * @return int|false
     */
    public static function xinclude(Context $ctx, ObjectEntry $document, int $options, ?Frame $frame = null): int|false
    {
        self::ensureDocument($document);
        $count = 0;
        $failed = false;
        /** @var array<int, true> $skipIds permanently failed include object ids */
        $skipIds = [];
        // Re-collect after each substitution so includes inside newly inserted XML are processed.
        for ($guard = 0; $guard < 10000; ++$guard) {
            $includes = self::collectXIncludeElements($document);
            $candidate = null;
            usort(
                $includes,
                static function (ObjectEntry $a, ObjectEntry $b): int {
                    return self::nodeDepth($b) <=> self::nodeDepth($a);
                }
            );
            foreach ($includes as $include) {
                if (!isset($skipIds[$include->id])) {
                    $candidate = $include;
                    break;
                }
            }
            if (null === $candidate) {
                break;
            }
            $result = self::processOneXInclude($ctx, $document, $candidate, $options, $frame);
            if (null === $result) {
                $failed = true;
                $skipIds[$candidate->id] = true;

                continue;
            }
            $count += $result;
        }

        if ($failed) {
            return -1;
        }

        return $count > 0 ? $count : false;
    }

    /** @return list<ObjectEntry> */
    private static function collectXIncludeElements(ObjectEntry $document): array
    {
        $ids = array_merge(
            self::collectElementsByTagNameNS($document, DomConstants::XINCLUDE_NS, 'include'),
            self::collectElementsByTagNameNS($document, DomConstants::XINCLUDE_OLD_NS, 'include')
        );
        $seen = [];
        $out = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $entry = DomRegistry::entry($id);
            if (null !== $entry && self::isXIncludeElement($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private static function isXIncludeElement(ObjectEntry $entry): bool
    {
        if (!self::isElement($entry)) {
            return false;
        }
        $local = self::readLocalName($entry);
        if ('include' !== $local) {
            return false;
        }
        $ns = self::readNamespaceUri($entry) ?? '';

        return DomConstants::XINCLUDE_NS === $ns || DomConstants::XINCLUDE_OLD_NS === $ns;
    }

    private static function isXIncludeFallbackElement(ObjectEntry $entry): bool
    {
        if (!self::isElement($entry)) {
            return false;
        }
        if ('fallback' !== self::readLocalName($entry)) {
            return false;
        }
        $ns = self::readNamespaceUri($entry) ?? '';

        return DomConstants::XINCLUDE_NS === $ns || DomConstants::XINCLUDE_OLD_NS === $ns;
    }

    private static function nodeDepth(ObjectEntry $node): int
    {
        $depth = 0;
        $parentId = DomRegistry::state($node)->parentId;
        while (null !== $parentId) {
            ++$depth;
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                break;
            }
            $parentId = DomRegistry::state($parent)->parentId;
        }

        return $depth;
    }

    /**
     * Process one xi:include. Returns substitution weight (1) on success, null on hard failure.
     */
    private static function processOneXInclude(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $include,
        int $options,
        ?Frame $frame
    ): ?int {
        $href = self::getAttribute($include, 'href') ?? '';
        $parse = self::getAttribute($include, 'parse') ?? '';
        if ('' === $parse) {
            $parse = 'xml';
        }
        $path = self::resolveXIncludeHref($include, $href);
        $contents = '' !== $path ? \PHPCompiler\ext\standard\VmFsReadNative::read($path) : false;
        if (false === $contents) {
            $display = '' !== $path ? $path : $href;
            self::triggerDomWarning(
                $frame,
                'DOMDocument::xinclude(): I/O warning : failed to load external entity "'.$display.'"'
            );
            $fallbackKids = self::xincludeFallbackChildren($include);
            if (null !== $fallbackKids) {
                self::replaceNodeWithNodes($ctx, $include, $fallbackKids);

                return 1;
            }
            self::triggerDomWarning(
                $frame,
                'DOMDocument::xinclude(): could not load '.$display.', and no fallback was found'
            );

            return null;
        }

        if ('text' === $parse) {
            $text = self::createTextNode($ctx, $contents, $document);
            self::replaceNodeWithNodes($ctx, $include, [$text]);

            return 1;
        }

        // parse="xml" (default) — insert the included document element (php-src / libxml).
        $tmpDoc = self::createDocument($ctx, null, '', null)->toObject();
        if (!self::loadXML($ctx, $tmpDoc, $contents, $frame, $options)) {
            $fallbackKids = self::xincludeFallbackChildren($include);
            if (null !== $fallbackKids) {
                self::replaceNodeWithNodes($ctx, $include, $fallbackKids);

                return 1;
            }
            self::triggerDomWarning(
                $frame,
                'DOMDocument::xinclude(): could not load '.$path.', and no fallback was found'
            );

            return null;
        }
        // libxml stamps the included doc URL to the resolved href so xmlNodeGetBase differs
        // from the include site — loadXML alone would leave default cwd (#24775).
        if ('' !== $path) {
            DomRegistry::state($tmpDoc)->documentUri = $path;
        }
        $rootVar = $tmpDoc->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $rootVar->type) {
            self::replaceNodeWithNodes($ctx, $include, []);

            return 1;
        }
        $sourceRoot = $rootVar->toObject();
        // Capture bases before import — after reparent, readBaseUri follows the target doc (#24775).
        $sourceBase = self::readBaseUri($sourceRoot);
        $targetBase = self::readBaseUri($include);
        $imported = self::importNodeEntry($ctx, $document, $sourceRoot, true);
        self::applyXIncludeBaseFixup($ctx, $imported, $sourceBase, $targetBase);
        self::replaceNodeWithNodes($ctx, $include, [$imported]);

        return 1;
    }

    /**
     * libxml {@code xmlXIncludeBaseFixup} — set relative {@code xml:base} when the included
     * node's base differs from the include site and the relative form contains {@code /}
     * (libxml2 xinclude.c; php-src ext/dom/document.c; #24775).
     */
    private static function applyXIncludeBaseFixup(
        Context $ctx,
        ObjectEntry $copy,
        string $sourceBase,
        string $targetBase
    ): void {
        if (!self::isElement($copy)) {
            return;
        }
        if ('' !== $sourceBase && $sourceBase !== $targetBase) {
            $relBase = self::buildRelativeUri($sourceBase, $targetBase);
            // libxml: omit when relative has no slash (same directory).
            if ('' !== $relBase && str_contains($relBase, '/')) {
                self::setAttributeNS($ctx, $copy, DomConstants::XML_NS_URI, 'xml:base', $relBase);

                return;
            }
        }
        // Bases equal or relative has no slash — drop any copied xml:base.
        self::removeAttributeNS($ctx, $copy, DomConstants::XML_NS_URI, 'base');
    }

    /**
     * libxml {@code xmlBuildRelativeURI}(uri, base) — URI expressed relative to base (#24775).
     */
    private static function buildRelativeUri(string $uri, string $base): string
    {
        if ('' === $uri) {
            return '';
        }
        if ('' === $base) {
            return $uri;
        }
        $u = parse_url($uri);
        $b = parse_url($base);
        if (!\is_array($u)) {
            $u = [];
        }
        if (!\is_array($b)) {
            $b = [];
        }
        // Bare filesystem paths (documentURI / loadXML defaults) — not scheme-bearing URLs.
        if (!isset($u['scheme']) && str_starts_with($uri, '/')) {
            $u = ['path' => $uri];
        }
        if (!isset($b['scheme']) && '' !== $base && 1 !== preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $base)) {
            $b = ['path' => $base];
        }
        $uScheme = $u['scheme'] ?? '';
        $bScheme = $b['scheme'] ?? '';
        $uHost = $u['host'] ?? '';
        $bHost = $b['host'] ?? '';
        if ($uScheme !== $bScheme || $uHost !== $bHost) {
            return $uri;
        }
        $up = $u['path'] ?? '';
        $bp = $b['path'] ?? '';
        if ('' === $bp) {
            $bp = '/';
        }
        if (!str_ends_with($bp, '/')) {
            $slash = strrpos($bp, '/');
            $bp = false === $slash ? '/' : substr($bp, 0, $slash + 1);
        }
        $uSeg = ('/' === $up || '' === $up) ? [] : explode('/', trim($up, '/'));
        $bSeg = ('/' === $bp || '' === $bp) ? [] : explode('/', trim($bp, '/'));
        $i = 0;
        $uCount = \count($uSeg);
        $bCount = \count($bSeg);
        while ($i < $uCount && $i < $bCount && $uSeg[$i] === $bSeg[$i]) {
            ++$i;
        }
        $rel = array_merge(array_fill(0, $bCount - $i, '..'), array_slice($uSeg, $i));
        $out = implode('/', $rel);
        if (isset($u['query'])) {
            $out .= '?'.$u['query'];
        }
        if (isset($u['fragment'])) {
            $out .= '#'.$u['fragment'];
        }

        return '' === $out ? '.' : $out;
    }

    private static function resolveXIncludeHref(ObjectEntry $include, string $href): string
    {
        if ('' === $href) {
            return '';
        }
        if (1 === preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $href)) {
            if (str_starts_with($href, 'file://')) {
                return substr($href, 7);
            }

            return $href;
        }
        $base = self::readBaseUri($include);
        if ('' === $base) {
            $base = self::defaultDocumentUri();
        }
        $resolved = self::resolveUri($base, $href);
        if (str_starts_with($resolved, 'file://')) {
            return substr($resolved, 7);
        }

        return $resolved;
    }

    /**
     * Children of xi:fallback, or null when no fallback element is present.
     *
     * @return null|list<ObjectEntry>
     */
    private static function xincludeFallbackChildren(ObjectEntry $include): ?array
    {
        $state = DomRegistry::state($include);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !self::isXIncludeFallbackElement($child)) {
                continue;
            }
            $kids = [];
            foreach (DomRegistry::state($child)->childIds as $kidId) {
                $kid = DomRegistry::entry($kidId);
                if (null !== $kid) {
                    $kids[] = $kid;
                }
            }

            return $kids;
        }

        return null;
    }

    /**
     * Replace $old with $newNodes in document order (insert before, then remove).
     *
     * @param list<ObjectEntry> $newNodes
     */
    private static function replaceNodeWithNodes(Context $ctx, ObjectEntry $old, array $newNodes): void
    {
        $parentId = DomRegistry::state($old)->parentId;
        if (null === $parentId) {
            return;
        }
        $parent = DomRegistry::entry($parentId);
        if (null === $parent) {
            return;
        }
        foreach ($newNodes as $node) {
            self::insertBefore($ctx, $parent, $node, $old);
        }
        self::removeChild($ctx, $parent, $old);
    }

    /** DOMDocument::validate() — in-document DTD validation via libxml2 FFI (php-src ext/dom/document.c; #18833). */
    public static function validate(Context $ctx, ObjectEntry $document, ?Frame $frame = null): bool
    {
        self::ensureDocument($document);
        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::validate(): not implemented in this compiler build');

            return false;
        }

        $state = DomRegistry::state($document);
        $docXml = $state->sourceXml;
        if (null === $docXml || '' === $docXml) {
            if (null === self::parseDoctypeNameFromDocument($document)) {
                self::triggerDomWarning($frame, 'DOMDocument::validate(): no DTD found!');

                return false;
            }
            $docXml = self::serializeXmlForValidation($document);
        }

        $result = VmDomValidationNative::validateDtdDocument($docXml);
        self::reportDomLibxmlValidationErrors(
            $ctx,
            $frame,
            'DOMDocument::validate()',
            $result['errors']
        );

        return $result['valid'];
    }

    private static function parseDoctypeNameFromDocument(ObjectEntry $document): ?string
    {
        $state = DomRegistry::state($document);
        if (null !== $state->doctypeName && '' !== $state->doctypeName) {
            return $state->doctypeName;
        }
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::isDocumentType($child)) {
                return DomRegistry::state($child)->nodeName;
            }
        }

        return null;
    }

    private static function serializeXmlForValidation(ObjectEntry $document): string
    {
        $state = DomRegistry::state($document);
        $lines = [self::serializeXmlDeclaration($state)];
        if (null !== $state->doctypeName) {
            $lines[] = self::serializeDoctype(
                $state->doctypeName,
                $state->doctypePublicId ?? '',
                $state->doctypeSystemId ?? '',
                self::documentDoctypeInternalSubset($state)
            );
        }
        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $rootVar->type) {
            $lines[] = self::serializeNode($rootVar->toObject(), 0, false, false);
        }

        return implode("\n", $lines);
    }

    /** DOMDocument::schemaValidate() — XSD file validation via libxml2 FFI (php-src ext/dom/document.c; #14370, #18806). */
    public static function schemaValidate(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        int $flags,
        ?Frame $frame = null
    ): bool {
        self::ensureDocument($document);
        unset($flags);
        // php-src routes the schema URI through php_libxml_external_entity_loader (#29596).
        if (null !== VmLibxml::getExternalEntityLoader()) {
            return self::schemaValidateViaExternalEntityLoader($ctx, $document, $filename, $frame);
        }
        if ('' === $filename || !is_file($filename)) {
            $schemaPath = $filename;
            if ('' !== $schemaPath && '/' !== $schemaPath[0]) {
                $schemaPath = getcwd() . '/' . $schemaPath;
            }
            if ('' !== $schemaPath) {
                // php-src/libxml2: I/O diagnostics → libxml ring under use_internal_errors (#20776).
                // PHP warnings carry DOMDocument::schemaValidate(): prefix (php-src #25843).
                self::reportDomMissingValidationResource(
                    $ctx,
                    $frame,
                    $schemaPath,
                    1757,
                    sprintf("Failed to locate the main schema resource at '%s'.\n", $schemaPath),
                    sprintf("Failed to locate the main schema resource at '%s'.", $schemaPath),
                    'DOMDocument::schemaValidate()'
                );
            }
            self::triggerDomWarning($frame, 'DOMDocument::schemaValidate(): Invalid Schema');

            return false;
        }
        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::schemaValidate(): not implemented in this compiler build');

            return false;
        }

        $docXml = self::saveXML($document);
        $ok = VmDomValidationNative::validateSchemaDocument($docXml, $filename);
        if (!$ok) {
            self::reportDomLibxmlValidationErrors(
                $ctx,
                $frame,
                'DOMDocument::schemaValidate()',
                VmDomValidationNative::consumeLastErrors(),
                'DOMDocument::schemaValidate(): Invalid Schema'
            );
        }

        return $ok;
    }

    /** DOMDocument::relaxNGValidate() — RelaxNG file validation via libxml2 FFI (php-src ext/dom/document.c; #14370, #18806). */
    public static function relaxNGValidate(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        ?Frame $frame = null
    ): bool {
        self::ensureDocument($document);
        // php-src routes the RNG URI through php_libxml_external_entity_loader (#29596).
        if (null !== VmLibxml::getExternalEntityLoader()) {
            return self::relaxNGValidateViaExternalEntityLoader($ctx, $document, $filename, $frame);
        }
        if ('' === $filename || !is_file($filename)) {
            $rngPath = $filename;
            if ('' !== $rngPath && '/' !== $rngPath[0]) {
                $rngPath = getcwd() . '/' . $rngPath;
            }
            if ('' !== $rngPath) {
                // php-src/libxml2: I/O diagnostics → libxml ring under use_internal_errors (#20776).
                // PHP warnings carry DOMDocument::relaxNGValidate(): prefix (php-src #25843).
                self::reportDomMissingValidationResource(
                    $ctx,
                    $frame,
                    $rngPath,
                    1065,
                    sprintf("xmlRelaxNGParse: could not load %s\n", $rngPath),
                    sprintf('xmlRelaxNGParse: could not load %s', $rngPath),
                    'DOMDocument::relaxNGValidate()'
                );
            }
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidate(): Invalid RelaxNG');

            return false;
        }
        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidate(): not implemented in this compiler build');

            return false;
        }

        $docXml = self::saveXML($document);
        $ok = VmDomValidationNative::validateRelaxNGDocument($docXml, $filename);
        if (!$ok) {
            self::reportDomLibxmlValidationErrors(
                $ctx,
                $frame,
                'DOMDocument::relaxNGValidate()',
                VmDomValidationNative::consumeLastErrors(),
                'DOMDocument::relaxNGValidate(): Invalid RelaxNG'
            );
        }

        return $ok;
    }

    /** DOMDocument::schemaValidateSource() — in-memory XSD via libxml2 FFI (php-src ext/dom/document.c; #18748, #19419, #20181). */
    public static function schemaValidateSource(
        Context $ctx,
        ObjectEntry $document,
        string $source,
        int $flags,
        ?Frame $frame = null
    ): bool {
        self::ensureDocument($document);
        unset($flags);
        self::rejectEmptyLoadSource($source, 'DOMDocument::schemaValidateSource()');
        $validationErrors = VmXml::validationErrorRecords($source);
        if ([] !== $validationErrors) {
            self::reportDomValidationSourceParseError($ctx, $source, 'DOMDocument::schemaValidateSource()', $frame, true);

            return false;
        }
        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::schemaValidateSource(): not implemented in this compiler build');

            return false;
        }

        $docXml = self::saveXML($document);
        $ok = VmDomValidationNative::validateSchemaDocumentSource($docXml, $source);
        if (!$ok) {
            self::reportDomLibxmlValidationErrors(
                $ctx,
                $frame,
                'DOMDocument::schemaValidateSource()',
                VmDomValidationNative::consumeLastErrors(),
                'DOMDocument::schemaValidateSource(): Invalid Schema'
            );
        }

        return $ok;
    }

    /** DOMDocument::relaxNGValidateSource() — in-memory RelaxNG via libxml2 FFI (php-src ext/dom/document.c; #18748, #20235). */
    public static function relaxNGValidateSource(
        Context $ctx,
        ObjectEntry $document,
        string $source,
        ?Frame $frame = null
    ): bool {
        self::ensureDocument($document);
        self::rejectEmptyLoadSource($source, 'DOMDocument::relaxNGValidateSource()');
        $validationErrors = VmXml::validationErrorRecords($source);
        if ([] !== $validationErrors) {
            self::reportDomValidationSourceParseError($ctx, $source, 'DOMDocument::relaxNGValidateSource()', $frame, false);

            return false;
        }

        if (preg_match('/<grammar\b/i', $source) && !preg_match('/<start\b/i', $source)) {
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidateSource(): grammar has no children');
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidateSource(): Element <grammar> has no <start>');
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidateSource(): Invalid RelaxNG');

            return false;
        }

        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidateSource(): not implemented in this compiler build');

            return false;
        }

        $docXml = self::saveXML($document);
        $ok = VmDomValidationNative::validateRelaxNGDocumentSource($docXml, $source);
        if (!$ok) {
            self::reportDomLibxmlValidationErrors(
                $ctx,
                $frame,
                'DOMDocument::relaxNGValidateSource()',
                VmDomValidationNative::consumeLastErrors(),
                'DOMDocument::relaxNGValidateSource(): Invalid RelaxNG'
            );
        }

        return $ok;
    }

    /**
     * Missing XSD/RNG path — two libxml I/O records + PHP Invalid* warning (#20776, re-#17453/#20181, #25843).
     *
     * Under libxml_use_internal_errors(true): codes 1549 + $secondCode land in libxml_get_errors();
     * otherwise both messages are PHP warnings with DOMDocument::{method}(): prefix (php-src
     * php_libxml_error_handler / ext/dom/document.c).
     */
    private static function reportDomMissingValidationResource(
        Context $ctx,
        ?Frame $frame,
        string $path,
        int $secondCode,
        string $secondMessage,
        string $secondWarningMessage,
        string $methodLabel
    ): void {
        $prefix = $methodLabel.': ';
        $ioMessage = sprintf("failed to load external entity \"%s\"\n", $path);
        VmLibxml::handleError(
            $ctx,
            [
                'level' => LibxmlConstants::LIBXML_ERR_WARNING,
                'code' => 1549,
                'column' => 0,
                'message' => $ioMessage,
                'file' => '',
                'line' => 0,
            ],
            $frame,
            null,
            sprintf('%sI/O warning : failed to load external entity "%s"', $prefix, $path)
        );
        VmLibxml::handleError(
            $ctx,
            [
                'level' => LibxmlConstants::LIBXML_ERR_ERROR,
                'code' => $secondCode,
                'column' => 0,
                'message' => $secondMessage,
                'file' => '',
                'line' => 0,
            ],
            $frame,
            null,
            $prefix.$secondWarningMessage
        );
    }

    /**
     * schemaValidate()/relaxNGValidate() with libxml_set_external_entity_loader (#29596).
     *
     * php-src xmlSchemaNewParserCtxt / xmlRelaxNGNewParserCtxt invoke
     * php_libxml_external_entity_loader for the filename URI — including when the path exists
     * on disk — so a custom resolver can substitute or reject the resource.
     */
    private static function schemaValidateViaExternalEntityLoader(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        ?Frame $frame
    ): bool {
        $schemaPath = self::absoluteDomValidationResourcePath($filename);
        if ('' === $schemaPath) {
            self::triggerDomWarning($frame, 'DOMDocument::schemaValidate(): Invalid Schema');

            return false;
        }

        $content = VmLibxml::resolveExternalEntityContent($ctx, null, $schemaPath, $frame);
        if (null === $content) {
            self::reportDomSchemaResourceParseFailure($ctx, $frame, $schemaPath);
            self::triggerDomWarning($frame, 'DOMDocument::schemaValidate(): Invalid Schema');

            return false;
        }
        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::schemaValidate(): not implemented in this compiler build');

            return false;
        }

        $docXml = self::saveXML($document);
        $ok = VmDomValidationNative::validateSchemaDocumentSource($docXml, $content);
        if (!$ok) {
            $errors = self::filterDomMemParserResourceErrors(
                VmDomValidationNative::consumeLastErrors()
            );
            self::reportDomLibxmlValidationErrors(
                $ctx,
                $frame,
                'DOMDocument::schemaValidate()',
                $errors
            );
            if (false === VmDomValidationNative::lastSchemaResourceParsed()) {
                self::reportDomSchemaResourceParseFailure($ctx, $frame, $schemaPath);
                self::triggerDomWarning($frame, 'DOMDocument::schemaValidate(): Invalid Schema');
            }
        }

        return $ok;
    }

    private static function relaxNGValidateViaExternalEntityLoader(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        ?Frame $frame
    ): bool {
        $rngPath = self::absoluteDomValidationResourcePath($filename);
        if ('' === $rngPath) {
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidate(): Invalid RelaxNG');

            return false;
        }

        $content = VmLibxml::resolveExternalEntityContent($ctx, null, $rngPath, $frame);
        if (null === $content) {
            self::reportDomRelaxNGResourceLoadFailure($ctx, $frame, $rngPath);
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidate(): Invalid RelaxNG');

            return false;
        }
        if (!VmDomValidationNative::available()) {
            self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidate(): not implemented in this compiler build');

            return false;
        }

        $docXml = self::saveXML($document);
        $ok = VmDomValidationNative::validateRelaxNGDocumentSource($docXml, $content);
        if (!$ok) {
            $errors = self::filterDomMemParserResourceErrors(
                VmDomValidationNative::consumeLastErrors()
            );
            self::reportDomLibxmlValidationErrors(
                $ctx,
                $frame,
                'DOMDocument::relaxNGValidate()',
                $errors
            );
            if (false === VmDomValidationNative::lastSchemaResourceParsed()) {
                self::reportDomRelaxNGResourceLoadFailure($ctx, $frame, $rngPath);
                self::triggerDomWarning($frame, 'DOMDocument::relaxNGValidate(): Invalid RelaxNG');
            }
        }

        return $ok;
    }

    /**
     * Drop mem-parser placeholders (in_memory_buffer / could not parse schemas) so entity-loader
     * paths can emit php-src's filename-bearing SCHEMAP/RelaxNG diagnostics instead (#29596).
     *
     * @param list<array{level: int, code: int, column: int, message: string, file: string, line: int}> $errors
     * @return list<array{level: int, code: int, column: int, message: string, file: string, line: int}>
     */
    private static function filterDomMemParserResourceErrors(array $errors): array
    {
        $out = [];
        foreach ($errors as $record) {
            $message = $record['message'];
            if ("Failed to parse the XML resource 'in_memory_buffer'." === $message
                || 'xmlRelaxNGParse: could not parse schemas' === $message
            ) {
                continue;
            }
            $out[] = $record;
        }

        return $out;
    }

    /** Absolute path for schema/RNG URI as passed to php_libxml_external_entity_loader (#29596). */
    private static function absoluteDomValidationResourcePath(string $filename): string
    {
        if ('' === $filename || '/' === $filename[0]) {
            return $filename;
        }

        return getcwd().'/'.$filename;
    }

    /** libxml2 SCHEMAP / code 3067 after entity-loader schema load failure (php-src #29596). */
    private static function reportDomSchemaResourceParseFailure(
        Context $ctx,
        ?Frame $frame,
        string $schemaPath
    ): void {
        $message = sprintf("Failed to parse the XML resource '%s'.", $schemaPath);
        VmLibxml::handleError(
            $ctx,
            [
                'level' => LibxmlConstants::LIBXML_ERR_ERROR,
                'code' => 3067,
                'column' => 0,
                'message' => $message,
                'file' => '',
                'line' => 0,
            ],
            $frame,
            null,
            'DOMDocument::schemaValidate(): '.$message
        );
    }

    /** libxml2 RelaxNG code 1065 after entity-loader RNG load failure (php-src #29596). */
    private static function reportDomRelaxNGResourceLoadFailure(
        Context $ctx,
        ?Frame $frame,
        string $rngPath
    ): void {
        $message = sprintf('xmlRelaxNGParse: could not load %s', $rngPath);
        VmLibxml::handleError(
            $ctx,
            [
                'level' => LibxmlConstants::LIBXML_ERR_ERROR,
                'code' => 1065,
                'column' => 0,
                'message' => $message,
                'file' => '',
                'line' => 0,
            ],
            $frame,
            null,
            'DOMDocument::relaxNGValidate(): '.$message
        );
    }

    /**
     * Route libxml2 validation diagnostics through VmLibxml (php-src php_libxml_error_handler; #20181).
     *
     * Under libxml_use_internal_errors(true), errors land in libxml_get_errors(); otherwise PHP warnings
     * carry the DOMDocument::method(): prefix.
     *
     * @param list<array{level: int, code: int, column: int, message: string, file: string, line: int}> $errors
     */
    private static function reportDomLibxmlValidationErrors(
        Context $ctx,
        ?Frame $frame,
        string $methodLabel,
        array $errors,
        ?string $fallbackWarning = null
    ): void {
        if ([] === $errors) {
            if (null !== $fallbackWarning) {
                self::triggerDomWarning($frame, $fallbackWarning);
            }

            return;
        }

        foreach ($errors as $record) {
            VmLibxml::handleError(
                $ctx,
                $record,
                $frame,
                null,
                $methodLabel.': '.$record['message']
            );
        }
    }

    /**
     * DOMDocument::schemaValidateSource()/relaxNGValidateSource() libxml warning surface
     * (php-src ext/dom/document.c via libxml2; #18748).
     */
    private static function reportDomValidationSourceParseError(
        Context $ctx,
        string $source,
        string $methodLabel,
        ?Frame $frame,
        bool $isSchema
    ): void {
        $record = VmXml::validationErrorRecord($source);
        if (null === $record) {
            $record = [
                'level' => LibxmlConstants::LIBXML_ERR_FATAL,
                'code' => 4,
                'column' => 1,
                'message' => 'Malformed XML document',
                'file' => '',
                'line' => 1,
            ];
        }

        $prefix = $methodLabel.': ';
        $line = $record['line'];
        VmLibxml::handleError(
            $ctx,
            $record,
            $frame,
            null,
            $prefix.'Entity: line '.$line.': parser error : '.$record['message']
        );

        $snippet = trim($source);
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix.$snippet);

        $caretColumn = self::domLibxmlCaretColumn($snippet, $record);
        VmLibxml::handleError($ctx, $record, $frame, null, $prefix.str_repeat(' ', $caretColumn).'^');

        if ($isSchema) {
            self::triggerDomWarning($frame, $prefix."Failed to parse the XML resource 'in_memory_buffer'.");
            self::triggerDomWarning($frame, $prefix.'Invalid Schema');

            return;
        }

        self::triggerDomWarning($frame, $prefix.'xmlRelaxNGParse: could not parse schemas');
        self::triggerDomWarning($frame, $prefix.'Invalid RelaxNG');
    }

    /**
     * DOMNode::C14N() — inclusive canonical XML (php-src ext/dom/node.c; #14409, #19741).
     *
     * libxml xmlC14NDocDumpMemory only serializes nodes in the document tree.
     * Orphans, removeChild results, cloneNode results, and fragment-only nodes
     * yield an empty string (even when ownerDocument is set).
     *
     * When $xpath is non-null, uses xmlC14NDocSaveTo-equivalent nodeset filtering (#20257).
     *
     * Relative namespace URIs are invalid for C14N (libxml xmlC14NCheckForRelativeNamespaces);
     * returns false with libxml-style warnings (#22378).
     *
     * @param ?array<mixed> $xpath
     * @param ?array<mixed> $nsPrefixes
     */
    public static function c14n(
        Context $ctx,
        ObjectEntry $node,
        bool $exclusive,
        bool $withComments,
        ?array $xpath,
        ?array $nsPrefixes,
        ?Frame $frame = null,
        string $methodLabel = 'DOMNode::C14N'
    ): string|false {
        if (null !== $xpath) {
            if (!DomRegistry::has($node)) {
                return false;
            }
            // libxml checks the whole document before xpath nodeset dump (#22378).
            if (self::c14nDocumentHasRelativeNamespace($node)) {
                self::reportC14NRelativeNamespaceFailure($ctx, $frame, $methodLabel);

                return false;
            }

            return self::c14nWithXPathNodeset($ctx, $node, $exclusive, $withComments, $xpath, $nsPrefixes);
        }
        unset($nsPrefixes);
        if (!DomRegistry::has($node)) {
            return false;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            if (self::c14nDocumentHasRelativeNamespace($node)) {
                self::reportC14NRelativeNamespaceFailure($ctx, $frame, $methodLabel);

                return false;
            }
            // libxml xmlC14NDocDumpMemory on the document: emit child PIs/comments/element
            // in document order, joined by "\n"; doctype is omitted (#21659).
            return self::c14nSerializeDocumentChildren($node, $withComments, $exclusive);
        }
        // php-src zim_dom_node_C14N / xmlC14NDocDumpMemory: disconnected nodes → "" (#19741).
        // Orphans skip the relative-NS failure path (Zend returns "" without warnings).
        if (!self::isConnected($node)) {
            return '';
        }
        if (self::c14nDocumentHasRelativeNamespace($node)) {
            self::reportC14NRelativeNamespaceFailure($ctx, $frame, $methodLabel);

            return false;
        }

        $payload = self::c14nSerializeNode($node, $withComments, $exclusive, []);
        if (false === $payload) {
            return false;
        }
        // libxml appends a trailing newline when the C14N root is a PI or comment (#21659).
        if ('' !== $payload
            && (self::isProcessingInstruction($node) || self::isCommentNode($node))
        ) {
            return $payload."\n";
        }

        return $payload;
    }

    /**
     * True when any xmlns declaration in the node's document (or subtree) has a relative URI.
     *
     * Matches libxml xmlC14NCheckForRelativeNamespaces / xmlParseURI scheme==NULL (#22378).
     */
    private static function c14nDocumentHasRelativeNamespace(ObjectEntry $node): bool
    {
        $document = self::isDocument($node) ? $node : self::ownerDocumentEntry($node);
        $root = (null !== $document && self::isDocument($document)) ? $document : $node;

        return self::c14nSubtreeHasRelativeNamespaceUri($root);
    }

    /**
     * Walk element namespaceDeclarations; empty href is namespace undeclaration (not relative).
     */
    private static function c14nSubtreeHasRelativeNamespaceUri(ObjectEntry $entry): bool
    {
        if (!DomRegistry::has($entry)) {
            return false;
        }
        $state = DomRegistry::state($entry);
        if (self::isElement($entry)) {
            foreach ($state->namespaceDeclarations as $uri) {
                if (self::isRelativeNamespaceUri($uri)) {
                    return true;
                }
            }
        }
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && self::c14nSubtreeHasRelativeNamespaceUri($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relative namespace URI per libxml xmlParseURI (no scheme) — C14N rejects these (#22378).
     */
    private static function isRelativeNamespaceUri(string $uri): bool
    {
        if ('' === $uri) {
            return false;
        }

        // Absolute when a URI scheme is present (ALPHA *(ALPHA / DIGIT / "+" / "-" / ".") ":").
        return 1 !== preg_match('/^[A-Za-z][A-Za-z0-9+\-.]*:/', $uri);
    }

    /**
     * libxml C14N relative-NS failure diagnostics (php-src php_libxml_error_handler; #22378).
     */
    private static function reportC14NRelativeNamespaceFailure(
        Context $ctx,
        ?Frame $frame,
        string $methodLabel
    ): void {
        $rows = [
            [
                1955,
                "Relative namespace UR is invalid here : (null)\n",
                $methodLabel.'(): Relative namespace UR is invalid here : (null)',
            ],
            [
                1,
                "Internal error : checking for relative namespaces\n",
                $methodLabel.'(): Internal error : checking for relative namespaces',
            ],
            [
                1,
                "Internal error : processing docs children list\n",
                $methodLabel.'(): Internal error : processing docs children list',
            ],
        ];
        foreach ($rows as [$code, $message, $warning]) {
            VmLibxml::handleError(
                $ctx,
                [
                    'level' => LibxmlConstants::LIBXML_ERR_ERROR,
                    'code' => $code,
                    'column' => 0,
                    'message' => $message,
                    'file' => '',
                    'line' => 0,
                ],
                $frame,
                null,
                $warning
            );
        }
    }

    /**
     * Document-node C14N: serialize document children (PI/comment/element) with "\n" joins.
     *
     * Matches libxml xmlC14NDocDumpMemory on the document node (#21659). DocumentType is omitted.
     */
    private static function c14nSerializeDocumentChildren(
        ObjectEntry $document,
        bool $withComments,
        bool $exclusive
    ): string {
        $state = DomRegistry::state($document);
        $chunks = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || self::isDocumentType($child)) {
                continue;
            }
            $chunk = self::c14nSerializeNode($child, $withComments, $exclusive, []);
            if (false === $chunk || '' === $chunk) {
                continue;
            }
            $chunks[] = $chunk;
        }

        return implode("\n", $chunks);
    }

    /**
     * C14N with xpath nodeset filter (php-src dom_canonicalization + xmlC14NDocSaveTo; #20257).
     *
     * @param array<mixed> $xpath
     * @param ?array<mixed> $nsPrefixes
     */
    private static function c14nWithXPathNodeset(
        Context $ctx,
        ObjectEntry $node,
        bool $exclusive,
        bool $withComments,
        array $xpath,
        ?array $nsPrefixes
    ): string|false {
        if (!\array_key_exists('query', $xpath)) {
            throw new \ValueError('DOMNode::C14N(): Argument #3 ($xpath) must have a "query" key');
        }
        $query = $xpath['query'];
        if (!\is_string($query)) {
            throw new \TypeError(\sprintf(
                'DOMNode::C14N(): Argument #3 ($xpath) "query" option must be a string, %s given',
                get_debug_type($query)
            ));
        }
        if (!DomRegistry::has($node)) {
            return false;
        }
        $document = self::isDocument($node) ? $node : self::ownerDocumentEntry($node);
        if (null === $document || !self::isDocument($document)) {
            return false;
        }
        $xpathObj = VmDomXPath::create($ctx, $document);
        if (isset($xpath['namespaces']) && \is_array($xpath['namespaces'])) {
            foreach ($xpath['namespaces'] as $prefix => $uri) {
                if (\is_string($prefix) && \is_string($uri)) {
                    VmDomXPath::registerNamespace($xpathObj, $prefix, $uri);
                }
            }
        }
        $contextNode = self::isDocument($node) ? null : $node;
        $listVar = VmDomXPath::query($ctx, $xpathObj, $query, $contextNode, false);
        $listVar = $listVar->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $listVar->type) {
            throw new \Error('XPath query did not return a nodeset');
        }
        $list = $listVar->toObject();
        if (!self::isNodeList($list)) {
            throw new \Error('XPath query did not return a nodeset');
        }
        $inSet = [];
        foreach (DomRegistry::state($list)->listNodeIds as $id) {
            $inSet[$id] = true;
        }
        unset($exclusive, $nsPrefixes);

        return self::c14nSerializeDocumentNodeset($document, $inSet, $withComments);
    }

    /**
     * Walk the document emitting only nodes in $inSet (libxml xmlC14NDocSaveTo; #20257).
     *
     * @param array<int, true> $inSet
     */
    private static function c14nSerializeDocumentNodeset(
        ObjectEntry $document,
        array $inSet,
        bool $withComments
    ): string {
        if ([] === $inSet) {
            return '';
        }
        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $rootVar->type) {
            return '';
        }

        return self::c14nSerializeNodesetNode($rootVar->toObject(), $inSet, $withComments);
    }

    /**
     * @param array<int, true> $inSet
     */
    private static function c14nSerializeNodesetNode(
        ObjectEntry $entry,
        array $inSet,
        bool $withComments
    ): string {
        if (!DomRegistry::has($entry)) {
            return '';
        }
        if (self::isElement($entry)) {
            $state = DomRegistry::state($entry);
            if (isset($inSet[$entry->id])) {
                $name = self::escapeName($state->nodeName);
                $attrPart = self::c14nSerializeAttributesInNodeset($entry, $inSet);
                $parts = ['<'.$name.$attrPart.'>'];
                foreach ($state->childIds as $childId) {
                    $child = DomRegistry::entry($childId);
                    if (null !== $child) {
                        $parts[] = self::c14nSerializeNodesetNode($child, $inSet, $withComments);
                    }
                }
                $parts[] = '</'.$name.'>';

                return implode('', $parts);
            }
            $parts = [self::c14nSerializeAttributesInNodeset($entry, $inSet)];
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $parts[] = self::c14nSerializeNodesetNode($child, $inSet, $withComments);
                }
            }

            return implode('', $parts);
        }
        if (!isset($inSet[$entry->id])) {
            return '';
        }
        if (self::isTextNode($entry)) {
            return self::escapeText(DomRegistry::state($entry)->textContent ?? '');
        }
        if (self::isCdataNode($entry)) {
            return DomRegistry::state($entry)->textContent ?? '';
        }
        if (self::isCommentNode($entry)) {
            if (!$withComments) {
                return '';
            }

            return '<!--'.(DomRegistry::state($entry)->textContent ?? '').'-->';
        }
        if (self::isProcessingInstruction($entry)) {
            return self::c14nSerializeProcessingInstruction($entry);
        }
        if (self::isEntityReference($entry)) {
            return '&'.self::escapeName(DomRegistry::state($entry)->nodeName).';';
        }
        if (self::isAttr($entry)) {
            $state = DomRegistry::state($entry);

            return ' '.self::escapeName($state->nodeName).'="'.self::escapeAttr($state->textContent ?? '').'"';
        }

        return '';
    }

    /**
     * Attributes on $element that are members of the C14N nodeset (#20257).
     *
     * @param array<int, true> $inSet
     */
    private static function c14nSerializeAttributesInNodeset(ObjectEntry $element, array $inSet): string
    {
        $state = DomRegistry::state($element);
        $entries = [];
        foreach ($state->attributes as $aname => $avalue) {
            if (self::isXmlnsAttributeName($aname)) {
                continue;
            }
            $attrId = $state->attributeNodeIds[$aname] ?? null;
            if (null === $attrId || !isset($inSet[$attrId])) {
                continue;
            }
            $entries[] = [
                'name' => $aname,
                'value' => $avalue,
                'ns' => $state->attributeNamespaces[$aname] ?? '',
                'nsDecl' => false,
            ];
        }
        if ([] === $entries) {
            return '';
        }
        $n = \count($entries);
        for ($i = 1; $i < $n; ++$i) {
            $key = $entries[$i];
            $j = $i - 1;
            while ($j >= 0 && self::c14nAttrCompare($entries[$j], $key) > 0) {
                $entries[$j + 1] = $entries[$j];
                --$j;
            }
            $entries[$j + 1] = $key;
        }
        $parts = [];
        foreach ($entries as $attr) {
            $parts[] = self::escapeName($attr['name']).'="'.self::escapeAttr($attr['value']).'"';
        }

        return ' '.implode(' ', $parts);
    }

    /**
     * DOMNode::C14NFile() — write canonical XML bytes (php-src ext/dom/node.c; #14409).
     *
     * @param ?array<mixed> $xpath
     * @param ?array<mixed> $nsPrefixes
     */
    public static function c14nFile(
        Context $ctx,
        ObjectEntry $node,
        string $uri,
        bool $exclusive,
        bool $withComments,
        ?array $xpath,
        ?array $nsPrefixes,
        ?Frame $frame = null
    ): int|false {
        $payload = self::c14n(
            $ctx,
            $node,
            $exclusive,
            $withComments,
            $xpath,
            $nsPrefixes,
            $frame,
            'DOMNode::C14NFile'
        );
        if (false === $payload) {
            return false;
        }
        $written = @file_put_contents($uri, $payload);
        if (false === $written) {
            return false;
        }

        return $written;
    }

    /**
     * @param array<string, string> $renderedNamespaces prefix => uri already emitted by ancestors in the node-set
     */
    private static function c14nSerializeNode(
        ObjectEntry $entry,
        bool $withComments,
        bool $exclusive,
        array $renderedNamespaces
    ): string|false {
        if (!DomRegistry::has($entry)) {
            return false;
        }
        if (self::isElement($entry)) {
            return self::c14nSerializeElement($entry, $withComments, $exclusive, $renderedNamespaces);
        }
        if (self::isTextNode($entry)) {
            return self::escapeText(DomRegistry::state($entry)->textContent ?? '');
        }
        if (self::isCdataNode($entry)) {
            return DomRegistry::state($entry)->textContent ?? '';
        }
        if (self::isCommentNode($entry)) {
            if (!$withComments) {
                return '';
            }

            return '<!--'.(DomRegistry::state($entry)->textContent ?? '').'-->';
        }
        if (self::isProcessingInstruction($entry)) {
            return self::c14nSerializeProcessingInstruction($entry);
        }
        if (self::isEntityReference($entry)) {
            return '&'.self::escapeName(DomRegistry::state($entry)->nodeName).';';
        }

        return false;
    }

    /**
     * Canonical XML processing instruction (W3C C14N 1.0 / libxml xmlC14NDocDumpMemory; #21659).
     *
     * ProcessingInstruction(PI) ::= '<?' PITarget (S PIValue)? '?>'
     * PIValue is not entity-escaped. Empty data omits the separating space.
     */
    private static function c14nSerializeProcessingInstruction(ObjectEntry $entry): string
    {
        $state = DomRegistry::state($entry);
        $target = $state->nodeName;
        $data = $state->textContent ?? '';
        if ('' === $data) {
            return '<?'.$target.'?>';
        }

        return '<?'.$target.' '.$data.'?>';
    }

    /**
     * @param array<string, string> $renderedNamespaces
     */
    private static function c14nSerializeElement(
        ObjectEntry $entry,
        bool $withComments,
        bool $exclusive,
        array $renderedNamespaces
    ): string {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        $nsToEmit = self::c14nNamespacesToEmit($entry, $exclusive, $renderedNamespaces);
        $attrPart = self::c14nSerializeAttributes($entry, $nsToEmit);
        $childRendered = $renderedNamespaces;
        foreach ($nsToEmit as $prefix => $uri) {
            $childRendered[$prefix] = $uri;
        }
        if ([] === $state->childIds) {
            return '<'.$name.$attrPart.'></'.$name.'>';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            $chunk = self::c14nSerializeNode($child, $withComments, $exclusive, $childRendered);
            if (false === $chunk) {
                continue;
            }
            $parts[] = $chunk;
        }

        return '<'.$name.$attrPart.'>'.implode('', $parts).'</'.$name.'>';
    }

    /**
     * Namespaces to render on this element (Inclusive/Exclusive C14N; php-src/libxml xmlC14NDocDumpMemory).
     *
     * @param array<string, string> $renderedNamespaces
     *
     * @return array<string, string> prefix => uri
     */
    private static function c14nNamespacesToEmit(
        ObjectEntry $entry,
        bool $exclusive,
        array $renderedNamespaces
    ): array {
        $inScope = self::c14nCollectInScopeNamespaces($entry);
        if ($exclusive) {
            $utilized = self::c14nUtilizedPrefixes($entry);
            $candidates = [];
            foreach ($utilized as $prefix => $_) {
                if (\array_key_exists($prefix, $inScope)) {
                    $candidates[$prefix] = $inScope[$prefix];
                }
            }
        } else {
            $candidates = $inScope;
        }
        $toEmit = [];
        foreach ($candidates as $prefix => $uri) {
            // xml prefix is never emitted (http://www.w3.org/TR/xml-c14n).
            if ('xml' === $prefix) {
                continue;
            }
            if (\array_key_exists($prefix, $renderedNamespaces) && $renderedNamespaces[$prefix] === $uri) {
                continue;
            }
            $toEmit[$prefix] = $uri;
        }

        return $toEmit;
    }

    /**
     * In-scope prefix→URI map at $entry (ancestor declarations; nearer wins).
     *
     * @return array<string, string>
     */
    private static function c14nCollectInScopeNamespaces(ObjectEntry $entry): array
    {
        $chain = [];
        $current = $entry;
        while (DomRegistry::has($current)) {
            if (self::isElement($current)) {
                $chain[] = $current;
            }
            $state = DomRegistry::state($current);
            if (null === $state->parentId) {
                break;
            }
            $parent = DomRegistry::entry($state->parentId);
            if (null === $parent || self::isDocument($parent)) {
                break;
            }
            $current = $parent;
        }
        $inScope = [];
        for ($i = \count($chain) - 1; $i >= 0; --$i) {
            $state = DomRegistry::state($chain[$i]);
            foreach ($state->namespaceDeclarations as $prefix => $uri) {
                $inScope[$prefix] = $uri;
            }
        }

        return $inScope;
    }

    /**
     * Prefixes visibly utilized by this element (exclusive C14N).
     *
     * @return array<string, true>
     */
    private static function c14nUtilizedPrefixes(ObjectEntry $entry): array
    {
        $state = DomRegistry::state($entry);
        $used = [];
        $prefix = $state->prefix ?? '';
        if (null !== $state->namespaceUri && '' !== $state->namespaceUri) {
            $used[$prefix] = true;
        } elseif ('' !== $prefix) {
            // Prefixed QName still utilizes the prefix when URI resolution lagged (#19467).
            $used[$prefix] = true;
        }
        foreach ($state->attributes as $aname => $avalue) {
            unset($avalue);
            if (self::isXmlnsAttributeName($aname)) {
                continue;
            }
            [$attrPrefix] = self::splitQualifiedName($aname);
            if ('' !== $attrPrefix) {
                $used[$attrPrefix] = true;
            }
        }

        return $used;
    }

    /**
     * @param array<string, string> $nsToEmit prefix => uri
     *
     * @return non-empty-string|''
     */
    private static function c14nSerializeAttributes(ObjectEntry $entry, array $nsToEmit): string
    {
        $state = DomRegistry::state($entry);
        $entries = [];
        foreach ($nsToEmit as $prefix => $uri) {
            $attrName = '' === $prefix ? 'xmlns' : 'xmlns:'.$prefix;
            $entries[] = [
                'name' => $attrName,
                'value' => $uri,
                'ns' => 'http://www.w3.org/2000/xmlns/',
                'nsDecl' => true,
            ];
        }
        foreach ($state->attributes as $aname => $avalue) {
            if (self::isXmlnsAttributeName($aname)) {
                // Already represented via $nsToEmit / in-scope map.
                continue;
            }
            $entries[] = [
                'name' => $aname,
                'value' => $avalue,
                'ns' => $state->attributeNamespaces[$aname] ?? '',
                'nsDecl' => false,
            ];
        }
        if ([] === $entries) {
            return '';
        }
        // Insertion sort — no closures (nested AOT helpers cannot compile usort callbacks; #19467).
        $n = \count($entries);
        for ($i = 1; $i < $n; ++$i) {
            $key = $entries[$i];
            $j = $i - 1;
            while ($j >= 0 && self::c14nAttrCompare($entries[$j], $key) > 0) {
                $entries[$j + 1] = $entries[$j];
                --$j;
            }
            $entries[$j + 1] = $key;
        }
        $parts = [];
        foreach ($entries as $attr) {
            $parts[] = self::escapeName($attr['name']).'="'.self::escapeAttr($attr['value']).'"';
        }

        return ' '.implode(' ', $parts);
    }

    /**
     * @param array{name: string, value: string, ns: string, nsDecl: bool} $a
     * @param array{name: string, value: string, ns: string, nsDecl: bool} $b
     */
    private static function c14nAttrCompare(array $a, array $b): int
    {
        if ($a['nsDecl'] && !$b['nsDecl']) {
            return -1;
        }
        if (!$a['nsDecl'] && $b['nsDecl']) {
            return 1;
        }
        if ($a['nsDecl'] && $b['nsDecl']) {
            if ('xmlns' === $a['name']) {
                return 'xmlns' === $b['name'] ? 0 : -1;
            }
            if ('xmlns' === $b['name']) {
                return 1;
            }

            return strcmp($a['name'], $b['name']);
        }
        $cmp = strcmp($a['ns'], $b['ns']);
        if (0 !== $cmp) {
            return $cmp;
        }

        return strcmp(self::attributeLocalName($a['name']), self::attributeLocalName($b['name']));
    }

    private static function attributeLocalName(string $qName): string
    {
        $colon = strpos($qName, ':');

        return false === $colon ? $qName : substr($qName, $colon + 1);
    }

    private static function triggerDomWarning(?Frame $frame, string $message): void
    {
        if (null === $frame || null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

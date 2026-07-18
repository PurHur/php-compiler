<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\libxml\VmLibxml;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

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

    public const PROP_XML_VERSION = 'xmlVersion';

    public const PROP_XML_STANDALONE = 'xmlStandalone';

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

    public const PROP_PUBLIC_ID = 'publicId';

    public const PROP_SYSTEM_ID = 'systemId';

    public const PROP_ENTITIES = 'entities';

    public const PROP_NOTATIONS = 'notations';

    public const PROP_NOTATION_NAME = 'notationName';

    public const PROP_TARGET = 'target';

    /** JIT/AOT: string id → DOMElement map mirrored from DomRegistry::elementIds (#17954). */
    public const PROP_ELEMENT_ID_MAP = '__phpcDomElementIdMap';

    /** JIT/AOT: DomRegistry object id for scalar helper bridges (#17954, #16075). */
    public const PROP_REGISTRY_ID = '__phpcDomRegistryId';

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
        $node->methods['append'] = new NodeAppend();
        $node->methodVisibility['append'] = $pub;
        if (CompilerVersion::supportsDomNodeReplaceChildren()) {
            $node->methods['replacechildren'] = new NodeReplaceChildren();
            $node->methodVisibility['replacechildren'] = $pub;
        }
        $node->methods['prepend'] = new NodePrepend();
        $node->methodVisibility['prepend'] = $pub;
        $node->methods['before'] = new NodeBefore();
        $node->methodVisibility['before'] = $pub;
        $node->methods['after'] = new NodeAfter();
        $node->methodVisibility['after'] = $pub;
        $node->methods['replacewith'] = new NodeReplaceWith();
        $node->methodVisibility['replacewith'] = $pub;
        $node->methods['remove'] = new NodeRemove();
        $node->methodVisibility['remove'] = $pub;
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
        $ctx->classes[self::CLASS_NODE] = $node;

        $text = new ClassEntry('DOMText');
        $text->isInternal = true;
        $text->parentLc = self::CLASS_CHARACTER_DATA;
        $text->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $text->properties[] = new ClassProperty(self::PROP_WHOLE_TEXT, null, $strProto);
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
        $ctx->classes[self::CLASS_CDATA] = $cdata;

        $characterData = new ClassEntry('DOMCharacterData');
        $characterData->isInternal = true;
        $characterData->parentLc = self::CLASS_NODE;
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
        $ctx->classes[self::CLASS_CHARACTER_DATA] = $characterData;

        $comment = new ClassEntry('DOMComment');
        $comment->isInternal = true;
        $comment->parentLc = self::CLASS_CHARACTER_DATA;
        $comment->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $ctx->classes[self::CLASS_COMMENT] = $comment;

        $entityRef = new ClassEntry('DOMEntityReference');
        $entityRef->isInternal = true;
        $entityRef->parentLc = self::CLASS_NODE;
        $entityRef->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $ctx->classes[self::CLASS_ENTITY_REFERENCE] = $entityRef;

        $entity = new ClassEntry('DOMEntity');
        $entity->isInternal = true;
        $entity->parentLc = self::CLASS_NODE;
        $entity->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $entity->properties[] = new ClassProperty(self::PROP_PUBLIC_ID, $nullProto, $strProto);
        $entity->properties[] = new ClassProperty(self::PROP_SYSTEM_ID, $nullProto, $strProto);
        $entity->properties[] = new ClassProperty(self::PROP_NOTATION_NAME, $nullProto, $strProto);
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
        $ctx->classes[self::CLASS_NAMESPACE_NODE] = $nsNode;

        $nodeList = new ClassEntry('DOMNodeList');
        $nodeList->isInternal = true;
        $nodeList->interfaces[] = 'countable';
        if (isset($ctx->classes['iterator'])) {
            $nodeList->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['traversable'])) {
            $nodeList->interfaces[] = 'traversable';
        }
        $nodeList->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
        $nodeList->methods['item'] = new NodeListItem();
        $nodeList->methodVisibility['item'] = $pub;
        $nodeList->methods['count'] = new NodeListCount();
        $nodeList->methodVisibility['count'] = $pub;
        $nodeList->methods['rewind'] = new NodeListRewind();
        $nodeList->methodVisibility['rewind'] = $pub;
        $nodeList->methods['valid'] = new NodeListValid();
        $nodeList->methodVisibility['valid'] = $pub;
        $nodeList->methods['current'] = new NodeListCurrent();
        $nodeList->methodVisibility['current'] = $pub;
        $nodeList->methods['key'] = new NodeListKey();
        $nodeList->methodVisibility['key'] = $pub;
        $nodeList->methods['next'] = new NodeListNext();
        $nodeList->methodVisibility['next'] = $pub;
        $ctx->classes[self::CLASS_NODE_LIST] = $nodeList;

        $namedNodeMap = new ClassEntry('DOMNamedNodeMap');
        $namedNodeMap->isInternal = true;
        $namedNodeMap->interfaces[] = 'countable';
        if (isset($ctx->classes['iterator'])) {
            $namedNodeMap->interfaces[] = 'iterator';
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
        $namedNodeMap->methods['rewind'] = new NamedNodeMapRewind();
        $namedNodeMap->methodVisibility['rewind'] = $pub;
        $namedNodeMap->methods['valid'] = new NamedNodeMapValid();
        $namedNodeMap->methodVisibility['valid'] = $pub;
        $namedNodeMap->methods['current'] = new NamedNodeMapCurrent();
        $namedNodeMap->methodVisibility['current'] = $pub;
        $namedNodeMap->methods['key'] = new NamedNodeMapKey();
        $namedNodeMap->methodVisibility['key'] = $pub;
        $namedNodeMap->methods['next'] = new NamedNodeMapNext();
        $namedNodeMap->methodVisibility['next'] = $pub;
        $ctx->classes[self::CLASS_NAMED_NODE_MAP] = $namedNodeMap;

        if (CompilerVersion::supportsDomTokenList()) {
            $tokenList = new ClassEntry('DOMTokenList');
            $tokenList->isInternal = true;
            $tokenList->interfaces[] = 'countable';
            $tokenList->properties[] = new ClassProperty(self::PROP_LENGTH, null, $intProto);
            $tokenList->properties[] = new ClassProperty(self::PROP_VALUE, null, $strProto);
            $tokenList->methods['add'] = new TokenListAdd();
            $tokenList->methodVisibility['add'] = $pub;
            $tokenList->methods['remove'] = new TokenListRemove();
            $tokenList->methodVisibility['remove'] = $pub;
            $tokenList->methods['contains'] = new TokenListContains();
            $tokenList->methodVisibility['contains'] = $pub;
            $tokenList->methods['toggle'] = new TokenListToggle();
            $tokenList->methodVisibility['toggle'] = $pub;
            $tokenList->methods['item'] = new TokenListItem();
            $tokenList->methodVisibility['item'] = $pub;
            $tokenList->methods['replace'] = new TokenListReplace();
            $tokenList->methodVisibility['replace'] = $pub;
            $tokenList->methods['supports'] = new TokenListSupports();
            $tokenList->methodVisibility['supports'] = $pub;
            $tokenList->methods['count'] = new TokenListCount();
            $tokenList->methodVisibility['count'] = $pub;
            $ctx->classes[self::CLASS_TOKEN_LIST] = $tokenList;
        }

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
        $ctx->classes[self::CLASS_PROCESSING_INSTRUCTION] = $processingInstruction;

        $document = new ClassEntry('DOMDocument');
        $document->isInternal = true;
        $document->parentLc = self::CLASS_NODE;
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
        $document->properties[] = new ClassProperty(self::PROP_XML_VERSION, null, $strProto);
        $document->properties[] = new ClassProperty(self::PROP_XML_STANDALONE, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        $document->properties[] = new ClassProperty(self::PROP_ELEMENT_ID_MAP, $nullProto, $arrayProto);
        $document->properties[] = new ClassProperty(self::PROP_REGISTRY_ID, null, $intProto);
        // ParentNode on Document (php-src ext/dom/parentnode.c; #19431).
        $document->properties[] = new ClassProperty(self::PROP_FIRST_ELEMENT_CHILD, $nullProto, $objProto);
        $document->properties[] = new ClassProperty(self::PROP_LAST_ELEMENT_CHILD, $nullProto, $objProto);
        $document->properties[] = new ClassProperty(self::PROP_CHILD_ELEMENT_COUNT, null, $intProto);
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
        $ctx->classes[self::CLASS_DOCUMENT] = $document;

        $element = new ClassEntry('DOMElement');
        $element->isInternal = true;
        $element->parentLc = self::CLASS_NODE;
        $element->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $element->properties[] = new ClassProperty(self::PROP_TAG_NAME, null, $strProto);
        $element->properties[] = new ClassProperty(self::PROP_ATTRIBUTES, $nullProto, $objProto);
        // ParentNode + NonDocumentTypeChildNode on Element (#19431).
        $element->properties[] = new ClassProperty(self::PROP_FIRST_ELEMENT_CHILD, $nullProto, $objProto);
        $element->properties[] = new ClassProperty(self::PROP_LAST_ELEMENT_CHILD, $nullProto, $objProto);
        $element->properties[] = new ClassProperty(self::PROP_CHILD_ELEMENT_COUNT, null, $intProto);
        $element->properties[] = new ClassProperty(self::PROP_NEXT_ELEMENT_SIBLING, $nullProto, $objProto);
        $element->properties[] = new ClassProperty(self::PROP_PREVIOUS_ELEMENT_SIBLING, $nullProto, $objProto);
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
        if (CompilerVersion::supportsDomTokenList()) {
            $element->properties[] = new ClassProperty(self::PROP_CLASS_LIST, $nullProto, $objProto);
        }
        $ctx->classes[self::CLASS_ELEMENT] = $element;

        $fragment = new ClassEntry('DOMDocumentFragment');
        $fragment->isInternal = true;
        $fragment->parentLc = self::CLASS_NODE;
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
        $ctx->classes[self::CLASS_DOCUMENT_FRAGMENT] = $fragment;
    }

    public static function createDocumentType(
        Context $ctx,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): Variable {
        $class = self::resolveNodeClass($ctx, null, self::CLASS_DOCUMENT_TYPE);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_TYPE_NODE;
        $state->nodeName = $qualifiedName;
        $state->publicId = $publicId;
        $state->systemId = $systemId;
        DomRegistry::attach($entry, $state);
        self::initDocumentTypePropertySlots($entry, $qualifiedName, $publicId, $systemId);
        $entitiesMap = self::createNamedNodeMap($ctx, []);
        $notationsMap = self::createNamedNodeMap($ctx, []);
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
        ?ObjectEntry $doctype
    ): Variable {
        if (null !== $doctype && !self::isDocumentType($doctype)) {
            throw new \TypeError(
                'DOMImplementation::createDocument(): Argument #3 ($doctype) must be of type DOMDocumentType or null'
            );
        }

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
        $state->namespaceUri = $namespaceUri;
        $state->documentElementName = $qualifiedName;
        if (null !== $doctype) {
            $dt = DomRegistry::state($doctype);
            $state->doctypeName = $dt->nodeName;
            $state->doctypePublicId = $dt->publicId;
            $state->doctypeSystemId = $dt->systemId;
        }
        DomRegistry::attach($entry, $state);
        self::ensureChildNodesList($ctx, $entry);

        if ('' !== $qualifiedName) {
            $rootVar = null !== $namespaceUri && '' !== $namespaceUri
                ? self::createElementNS($ctx, $namespaceUri, $qualifiedName, $entry)
                : self::createElement($ctx, $qualifiedName, $entry);
            $root = $rootVar->toObject();
            $state->childIds = [$root->id];
            $entry->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($root);
            self::linkChildToParent($root, $entry);
            self::propagateDocumentId($root, $entry->id);
            self::syncSubtree($ctx, $entry);
        }

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
                $ht->add($id, self::elementVariable($entry));
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
            if (self::CLASS_DOCUMENT_FRAGMENT !== strtolower($fragment->class->name)) {
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
        if (self::CLASS_DOCUMENT === $classLc) {
            self::ensureDocument($entry);

            return;
        }
        if (self::CLASS_DOCUMENT_FRAGMENT === $classLc) {
            self::ensureDocumentFragment($entry);

            return;
        }
        if (self::CLASS_ELEMENT !== $classLc) {
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

    public static function createElement(
        Context $ctx,
        string $name,
        ?ObjectEntry $ownerDocument = null,
        string $value = ''
    ): Variable {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ELEMENT);

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::writeElementNameSlots($entry, $name);
        self::initElementPropertySlots($entry);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $name;
        $state->localName = $name;
        $state->prefix = null;
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
        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ELEMENT);

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
        ?ObjectEntry $ownerDocument = null
    ): Variable {
        $class = self::resolveNodeClass($ctx, $ownerDocument, self::CLASS_ATTR);

        [$prefix, $localName] = self::splitQualifiedName($qualifiedName);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($qualifiedName);
        // php-src ext/dom/attr.c: Attr.name is the local name (libxml attr->name), not the QName.
        $entry->getProperty(self::PROP_NAME)->string($localName);
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
        return self::createAttributeNS($ctx, null, $name, $ownerDocument);
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
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(false);

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
            throw new \DOMException('Not Found Error', 8);
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
        $cachedId = $state->attributeNodeIds[$name] ?? null;
        if (null !== $cachedId) {
            $cached = DomRegistry::entry($cachedId);
            if (null !== $cached && self::isAttr($cached)) {
                self::syncAttributeNodeValue($ctx, $cached, $value, true);
                $cachedState = DomRegistry::state($cached);
                $cachedState->ownerElementId = $element->id;
                $cachedState->parentId = $element->id;
                $cached->getProperty(self::PROP_OWNER_ELEMENT)->object($element);

                return $cached;
            }
        }
        $ownerDocument = self::ownerDocumentEntry($element);
        $attrVar = self::createAttributeNS(
            $ctx,
            $state->attributeNamespaces[$name] ?? null,
            $name,
            $ownerDocument
        );
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

    public static function getAttributeNS(ObjectEntry $element, ?string $namespace, string $localName): string
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

        return '';
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
     * DOMElement::getInnerHTML() — serialize child nodes (php-src ext/dom/inner_html_mixin.c; #16916).
     */
    public static function getInnerHTML(ObjectEntry $element): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        if ([] === $state->childIds) {
            return '';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeHtmlNode($child);
            }
        }

        return implode('', $parts);
    }

    /**
     * DOMElement::getOuterHTML() — serialize element and descendants (php-src ext/dom/inner_html_mixin.c; #16916).
     */
    public static function getOuterHTML(ObjectEntry $element): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }

        return self::serializeHtmlElement($element);
    }

    public static function setAttributeNS(
        Context $ctx,
        ObjectEntry $element,
        ?string $namespace,
        string $qualifiedName,
        string $value
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $state = DomRegistry::state($element);
        // Real xmlns / xmlns:* nsDef — not Attr map entries (php-src element.c / nodemap.c; #19718).
        if ('xmlns' === $qualifiedName
            && (null === $namespace || '' === $namespace || self::isXmlnsNamespaceUri($namespace))) {
            $state->namespaceDeclarations[''] = $value;
            self::detachCachedAttributeIfAny($state, 'xmlns');
            unset($state->attributes['xmlns'], $state->attributeNamespaces['xmlns']);
            self::syncElementAttributes($ctx, $element);

            return;
        }
        if (str_starts_with($qualifiedName, 'xmlns:') && self::isXmlnsNamespaceUri($namespace)) {
            $prefix = substr($qualifiedName, 6);
            $state->namespaceDeclarations[$prefix] = $value;
            self::detachCachedAttributeIfAny($state, $qualifiedName);
            unset($state->attributes[$qualifiedName], $state->attributeNamespaces[$qualifiedName]);
            self::syncElementAttributes($ctx, $element);

            return;
        }
        $previousIdValue = $state->attributes[$qualifiedName] ?? null;
        // Apply namespace before the ID-bearing check so xml:id / namespaced id is recognized (#19870).
        $state->attributeNamespaces[$qualifiedName] = $namespace ?? '';
        $idBearing = self::elementAttributeIsIdBearing($element, $qualifiedName);
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
            throw new \DOMException('Not Found Error', 8);
        }
        self::applyIdAttributeRegistration($element, $name, $isId);
    }

    /** DOMElement::setIdAttributeNS() — namespaced ID map (php-src ext/dom/element.c; #15300). */
    public static function setIdAttributeNS(
        ObjectEntry $element,
        ?string $namespace,
        string $localName,
        bool $isId
    ): void {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        $qName = self::findAttributeQNameByNsAndLocal($element, $namespace, $localName);
        if (null === $qName) {
            throw new \DOMException('Not Found Error', 8);
        }
        self::applyIdAttributeRegistration($element, $qName, $isId);
    }

    /**
     * DOMElement::setIdAttributeNode() — mark an owned Attr as ID (php-src ext/dom/element.c; #20123).
     * Zend: NOT_FOUND_ERR when attr->parent != element.
     */
    public static function setIdAttributeNode(ObjectEntry $element, ObjectEntry $attr, bool $isId): void
    {
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
            throw new \DOMException('Not Found Error', 8);
        }
        self::applyIdAttributeRegistration($element, $name, $isId);
    }

    private static function applyIdAttributeRegistration(ObjectEntry $element, string $qName, bool $isId): void
    {
        $state = DomRegistry::state($element);
        $document = self::ownerDocumentEntry($element);
        if (null === $document) {
            throw new \DOMException('Not Found Error', 8);
        }
        self::unregisterElementId($document, $element);
        if ($isId) {
            $state->idAttributeName = $qName;
            self::registerElementId($document, $element);
        } else {
            $state->idAttributeName = null;
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

    private static function registerElementId(ObjectEntry $document, ObjectEntry $element): void
    {
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
        $docState->elementIds[$value] = $element->id;
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
        self::unregisterElementId($document, $element, $previousValue);
        if ($registerNext) {
            self::registerElementId($document, $element);
        } elseif (null !== $nodeState->idAttributeName && $qName === $nodeState->idAttributeName) {
            $nodeState->idAttributeName = null;
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
            return $state->documentUri ?? '';
        }

        $doc = self::ownerDocumentEntry($node);
        if (null === $doc) {
            return '';
        }
        $docState = DomRegistry::state($doc);
        $base = $docState->documentUri ?? '';
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
            $href = self::getAttribute($baseEl, 'href');
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
        $qName = self::getAttribute($element, 'xml:base');
        if ('' !== $qName) {
            return $qName;
        }
        $ns = self::getAttributeNS($element, DomConstants::XML_NS_URI, 'base');
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
     *
     * @param array<string, string> $attributes
     */
    private static function applyParsedAttributes(DomNodeState $state, array $attributes): void
    {
        $state->namespaceDeclarations = self::extractNamespaceDeclarations($attributes);
        $state->attributes = self::stripNamespaceDeclarationAttributes($attributes);
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
     */
    public static function getAttribute(ObjectEntry $element, string $name): string
    {
        if (!self::isElement($element)) {
            throw new \DOMException('Not an element node');
        }
        if (self::isXmlnsAttributeName($name)) {
            return self::namespaceDeclarationValue($element, $name) ?? '';
        }
        $state = DomRegistry::state($element);

        return $state->attributes[$name] ?? '';
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
            throw new \DOMException('Index size error', DomExceptionConstants::INDEX_SIZE_ERR);
        }
        self::writeCharacterDataContent($node, substr($data, 0, $offset).$arg.substr($data, $offset));
    }

    public static function characterDataDeleteData(ObjectEntry $node, int $offset, int $count): void
    {
        $data = self::characterDataReadContent($node);
        $len = \strlen($data);
        if ($offset < 0 || $offset > $len || $count < 0) {
            throw new \DOMException('Index size error', DomExceptionConstants::INDEX_SIZE_ERR);
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
            throw new \DOMException('Index size error', DomExceptionConstants::INDEX_SIZE_ERR);
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
            throw new \DOMException('Index size error', DomExceptionConstants::INDEX_SIZE_ERR);
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

        $trimmed = trim($xml);
        $decl = self::parseXmlDeclaration($trimmed);
        $idAttrByElement = self::parseDoctypeIdAttributes($trimmed);
        $generalEntities = self::parseDoctypeGeneralEntities($trimmed);
        $substituteEntities = 0 !== ($options & LibxmlConstants::LIBXML_NOENT);
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
                    $validationError['level']
                );
            }

            return false;
        }
        $root = self::parseElementTree(
            $ctx,
            $elementXml,
            $trimmed,
            $elementOffset,
            $generalEntities,
            $substituteEntities
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
        // php-src: preserveWhiteSpace=false and/or LIBXML_NOBLANKS → XML_PARSE_NOBLANKS (#20476).
        if (self::shouldStripBlankNodesOnLoad($document, $options)) {
            self::stripBlankTextNodesFromSubtree($root);
        }
        self::syncSubtree($ctx, $document);
        self::reindexDocumentIds($document, $root);
        self::syncElementIdMapProperty($document);
        $state->documentUri = self::defaultDocumentUri();
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

        return self::loadXML($ctx, $document, $contents, $frame, $options);
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

    /** php-src ext/dom/document.c — empty $filename rejected since PHP 8.0 (#18734). */
    private static function rejectEmptyFilename(string $filename, string $method): void
    {
        if ('' === $filename) {
            throw new \ValueError($method.': Argument #1 ($filename) must not be empty');
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

        return DomRegistry::entry($objectId);
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
                if (null !== $value && '' !== $value) {
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
     * Before remove/detach from the live tree — drop ID attrs (php-src ext/dom/node.c; #19212).
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
        if (null === $idAttr && $docState->isHtmlDocument && isset($nodeState->attributes['id'])) {
            $idAttr = 'id';
        }
        if (null === $idAttr && !$docState->isHtmlDocument) {
            if (isset($nodeState->attributes['xml:id'])) {
                $idAttr = 'xml:id';
            } elseif (isset($nodeState->attributes['id'])
                && self::XML_NAMESPACE_URI === ($nodeState->attributeNamespaces['id'] ?? '')) {
                $idAttr = 'id';
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
        int $level = LibxmlConstants::LIBXML_ERR_ERROR
    ): void {
        VmLibxml::handleError($ctx, [
            'level' => $level,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => '',
            'line' => 1,
        ], $frame, null, 'DOMDocument::loadXML(): '.$message.' in Entity, line: 1');
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
                $validationError['level']
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
        $doctypeVar = self::createDocumentType($ctx, $name, $publicId, $systemId);
        $doctype = $doctypeVar->toObject();
        $state = DomRegistry::state($document);
        $state->doctypeName = $name;
        $state->doctypePublicId = $publicId;
        $state->doctypeSystemId = $systemId;
        $state->doctypeId = $doctype->id;
        self::linkChildToParent($doctype, $document);
        self::propagateDocumentId($doctype, $document->id);

        return $doctype;
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
        if (!preg_match('/<!DOCTYPE\s+\S+\s*\[(.*)\]\s*>/s', $xml, $doctype)) {
            return $declared;
        }
        if (!preg_match_all('/<!ELEMENT\s+(\S+)\s+/', $doctype[1], $matches)) {
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
        if (!preg_match('/<!DOCTYPE\s+\S+\s*\[(.*)\]\s*>/s', $xml, $doctype)) {
            return $idAttrs;
        }
        if (!preg_match_all('/<!ATTLIST\s+(\S+)\s+(\S+)\s+ID\b/', $doctype[1], $matches, PREG_SET_ORDER)) {
            return $idAttrs;
        }
        foreach ($matches as $match) {
            $idAttrs[$match[1]] = $match[2];
        }

        return $idAttrs;
    }

    /**
     * @return array<string, string> general entity name => replacement text
     */
    private static function parseDoctypeGeneralEntities(string $xml): array
    {
        $entities = [];
        $subset = self::extractDoctypeInternalSubset($xml);
        if (null === $subset) {
            return $entities;
        }
        if (preg_match_all('/<!ENTITY\s+([A-Za-z_][\w:.-]*)\s+"([^"]*)"\s*>/', $subset, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entities[$match[1]] = $match[2];
            }
        }

        return $entities;
    }

    private static function extractDoctypeInternalSubset(string $xml): ?string
    {
        if (!preg_match('/<!DOCTYPE\s+\S+\s*\[(.*)\]\s*>/s', $xml, $match)) {
            return null;
        }

        return $match[1];
    }

    private static function populateDoctypeInternalSubset(
        Context $ctx,
        ObjectEntry $doctype,
        ObjectEntry $document,
        string $xml
    ): void {
        $subset = self::extractDoctypeInternalSubset($xml);
        if (null === $subset) {
            return;
        }

        /** @var list<int> $entityIds */
        $entityIds = [];
        if (preg_match_all('/<!ENTITY\s+([A-Za-z_][\w:.-]*)\s+"([^"]*)"\s*>/', $subset, $entityMatches, PREG_SET_ORDER)) {
            foreach ($entityMatches as $match) {
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

        $doctypeState = DomRegistry::state($doctype);
        $entitiesMap = self::createNamedNodeMap($ctx, $entityIds);
        $doctypeState->entitiesMapId = $entitiesMap->toObject()->id;
        $doctype->getProperty(self::PROP_ENTITIES)->copyFrom($entitiesMap);

        $notationsMap = self::createNamedNodeMap($ctx, $notationIds);
        $doctypeState->notationsMapId = $notationsMap->toObject()->id;
        $doctype->getProperty(self::PROP_NOTATIONS)->copyFrom($notationsMap);
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
    private static function appendParsedTextOrEntityRefs(
        Context $ctx,
        ObjectEntry $parent,
        string $text,
        ?ObjectEntry $ownerDocument,
        array $generalEntities,
        bool $substituteEntities = false
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
            if (false === $semi) {
                $buffer .= substr($text, $amp);
                break;
            }
            $refName = substr($text, $amp + 1, $semi - $amp - 1);
            if (isset($generalEntities[$refName])) {
                if ($substituteEntities) {
                    // Fold replacement into the text buffer (libxml NOENT merge).
                    $buffer .= self::expandGeneralEntityReplacement(
                        $generalEntities[$refName],
                        $generalEntities
                    );
                } else {
                    if ('' !== $buffer) {
                        $textNode = self::createTextNode($ctx, self::decodePredefinedXmlEntities($buffer), $ownerDocument);
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
                    $state->childIds[] = $entityRef->id;
                    self::linkChildToParent($entityRef, $parent);
                }
            } else {
                $decoded = self::decodePredefinedXmlEntity($refName);
                if (null !== $decoded) {
                    $buffer .= $decoded;
                } else {
                    $buffer .= substr($text, $amp, $semi - $amp + 1);
                }
            }
            $pos = $semi + 1;
        }
        if ('' !== $buffer) {
            $textNode = self::createTextNode($ctx, self::decodePredefinedXmlEntities($buffer), $ownerDocument);
            $state->childIds[] = $textNode->id;
            self::linkChildToParent($textNode, $parent);
        }
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
        if (self::isDocumentFragment($child)) {
            return self::appendFragmentChildren($ctx, $parent, $child);
        }
        // php-src ext/dom/node.c: Attr under Element installs via attribute map (not childNodes).
        if (self::isAttr($child)) {
            return self::appendOrInsertAttribute($ctx, $parent, $child);
        }

        if (!self::isTreeMutationChild($child)) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState = DomRegistry::state($parent);
        if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
            self::assertNotAncestorOfParent($parent, $child);
            self::appendDocumentChild($ctx, $parent, $child);
            self::syncSubtree($ctx, $parent);
            self::registerSubtreeElementIdsIfConnected($child);

            return $child;
        }

        if (DomConstants::XML_ELEMENT_NODE !== $parentState->nodeType
            && DomConstants::XML_DOCUMENT_FRAG_NODE !== $parentState->nodeType
        ) {
            throw new \DOMException('Hierarchy request error');
        }

        self::assertSameDocument($parent, $child);
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
        if (self::isDocumentFragment($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        if (!self::isElement($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertChildOfParent($parent, $oldChild, 'DOMNode::replaceChild()');
        self::assertSameDocument($parent, $newChild);
        self::assertNotAncestorOfParent($parent, $newChild);
        self::unregisterSubtreeElementIdsIfConnected($oldChild);
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        $index = self::childIndex($parentState->childIds, $oldChild->id);
        if (null === $index) {
            throw new \DOMException('Not found error');
        }
        $parentState->childIds[$index] = $newChild->id;
        self::linkChildToParent($oldChild, null);
        self::linkChildToParent($newChild, $parent);
        if (self::isDocument($parent)) {
            $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($newChild);
            $parentState->documentElementName = DomRegistry::state($newChild)->nodeName;
            self::propagateDocumentId($newChild, $parent->id);
        }
        self::syncSubtree($ctx, $parent);
        self::registerSubtreeElementIdsIfConnected($newChild);

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
        if (!self::isTreeMutationChild($newChild)) {
            throw new \DOMException('Hierarchy request error');
        }
        self::assertSameDocument($parent, $newChild);
        self::assertNotAncestorOfParent($parent, $newChild);
        if (null !== $refChild) {
            self::assertChildOfParent($parent, $refChild, 'DOMNode::insertBefore()');
        }
        self::detachNodeIfAttached($ctx, $newChild);
        $parentState = DomRegistry::state($parent);
        if (null === $refChild) {
            $parentState->childIds[] = $newChild->id;
        } else {
            $index = self::childIndex($parentState->childIds, $refChild->id);
            if (null === $index) {
                throw new \DOMException('Not found error');
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
        self::registerSubtreeElementIdsIfConnected($newChild);

        return $newChild;
    }

    public static function removeChild(Context $ctx, ObjectEntry $parent, ObjectEntry $child): ObjectEntry
    {
        self::assertMutationParent($parent);
        self::assertChildOfParent($parent, $child, 'DOMNode::removeChild()');
        self::unregisterSubtreeElementIdsIfConnected($child);
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

    private static function removeAllLiveStandardChildren(Context $ctx, ObjectEntry $parent): void
    {
        $parentState = DomRegistry::state($parent);
        $existingIds = $parentState->childIds;
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
     * DOMElement::insertAdjacentHTML() — parse HTML and insert by position (php-src ext/dom/dom_element.c; #16128).
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
            throw new \DOMException('Not Found Error');
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent) {
            throw new \DOMException('Not Found Error');
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
            throw new \DOMException('Not found error');
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
                throw new \DOMException('Not found error');
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

            return self::serializeNode($node, 0, $formatOutput, $noEmptyTag);
        }

        $lines = [self::serializeXmlDeclaration($state)];

        if (null !== $state->doctypeName) {
            $lines[] = self::serializeDoctype(
                $state->doctypeName,
                $state->doctypePublicId ?? '',
                $state->doctypeSystemId ?? ''
            );
        }

        if ([] !== $state->childIds) {
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $lines[] = self::serializeNode($child, 0, $formatOutput, $noEmptyTag);
                }
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
        $doctypeDecl = self::parseHtmlDoctypeDeclaration($trimmed);
        $afterDoctype = $trimmed;
        if (null !== $doctypeDecl) {
            $afterDoctype = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/is', '', $trimmed) ?? $trimmed;
        }
        $afterPreamble = $afterDoctype;
        while (preg_match('/^\s*<\?([^\s?]+)\s+(.*?)\?>\s*/s', $afterPreamble, $piMatch)) {
            $pi = self::createProcessingInstruction($ctx, $piMatch[1], $piMatch[2], $document);
            $childIds[] = $pi->id;
            self::linkChildToParent($pi, $document);
            self::propagateDocumentId($pi, $document->id);
            $afterPreamble = substr($afterPreamble, \strlen($piMatch[0]));
        }

        $source = self::normalizeHtmlLoadSource($html, $options);
        $root = self::parseHtmlElementTree($ctx, $source, $document, $frame);
        if (null === $root) {
            return false;
        }

        $state = DomRegistry::state($document);
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
        } elseif (!$noDefDtd) {
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
        $state->encoding = null;
        $state->xmlStandalone = false;
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        if (!$deferDocumentSlotSync) {
            $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));
        }
        self::linkChildToParent($root, $document);
        self::propagateDocumentId($root, $document->id);
        if (!$deferDocumentSlotSync) {
            self::syncSubtree($ctx, $document);
        }
        self::reindexDocumentIds($document, $root);
        if (!$deferDocumentSlotSync) {
            self::syncElementIdMapProperty($document);
        }
        $state->documentUri = self::defaultDocumentUri();

        return true;
    }

    public static function saveHTML(ObjectEntry $document, ?ObjectEntry $node = null, int $options = 0): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveHTML() called on non-document node in this compiler build');
        }

        if (null !== $node) {
            if (!self::isDomNode($node)) {
                throw new \TypeError('DOMDocument::saveHTML(): Argument #1 ($node) must be of type ?DOMNode');
            }

            return self::serializeHtmlNode($node, !$state->loadedViaXml);
        }

        $emptySelfClosing = !$state->loadedViaXml;
        $lines = [];
        if ([] !== $state->childIds) {
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $lines[] = self::serializeHtmlNode($child, $emptySelfClosing);
                }
            }
        } else {
            $lines[] = self::serializeHtmlDoctypeFromDocumentState($state);
            $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT === $rootVar->type) {
                $lines[] = self::serializeHtmlNode($rootVar->toObject(), $emptySelfClosing);
            } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
                $name = self::escapeName($state->documentElementName);
                $lines[] = $emptySelfClosing ? '<'.$name.'/>' : '<'.$name.'></'.$name.'>';
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
            if (str_starts_with(strtolower($rest), '!doctype') || str_starts_with(strtolower($rest), 'html')) {
                $close = strpos($trimmed, '>');
                if (false !== $close && str_starts_with(strtolower(ltrim(substr($trimmed, $pos + 1))), '!doctype')) {
                    return ltrim(substr($trimmed, $close + 1));
                }

                return $trimmed;
            }
        }

        return '<html><body>'.$trimmed.'</body></html>';
    }

    private static function parseHtmlElementTree(
        Context $ctx,
        string $html,
        ObjectEntry $ownerDocument,
        ?\PHPCompiler\Frame $frame = null
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
            return self::createHtmlElementFromTag($ctx, $open['tag'], $open['attrs'], '', $ownerDocument, $frame);
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
                $frame
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
                $frame
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

        $entry = self::createHtmlElementFromTag($ctx, $open['tag'], $open['attrs'], $inner, $ownerDocument, $frame);
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
    ): ObjectEntry {
        $localName = strtolower($tagName);
        // Pass owner so living Dom\HTMLDocument nodeClassMap → Dom\HTMLElement (#20418).
        $entry = self::createElement($ctx, $localName, $ownerDocument)->toObject();
        $state = DomRegistry::state($entry);
        $state->attributes = self::decodeHtmlAttributeMap(self::parseAttributes($attrPart));
        self::applyQualifiedElementNames($state, $localName);
        self::applyParsedAttributes($state, $state->attributes);
        self::appendHtmlChildren($ctx, $entry, $inner, $ownerDocument, $frame);

        return $entry;
    }

    private static function appendHtmlChildren(
        Context $ctx,
        ObjectEntry $parent,
        string $inner,
        ObjectEntry $ownerDocument,
        ?\PHPCompiler\Frame $frame = null,
    ): void {
        $state = DomRegistry::state($parent);
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
            $end = self::findHtmlElementEnd($inner, $pos);
            if (null === $end) {
                $tagName = self::detectHtmlUnclosedTagName($inner, $pos);
                if (null !== $tagName) {
                    self::reportDomLoadHtmlUnclosedTagWarnings($ctx, $tagName, $frame);
                }

                return;
            }
            $childHtml = substr($inner, $pos, $end - $pos);
            $child = self::parseHtmlElementTree($ctx, $childHtml, $ownerDocument, $frame);
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
                // Ancestor/other close: imply optional end tags first (libxml; #20247).
                while ([] !== $stack && end($stack) !== $name) {
                    if (!self::htmlElementHasOptionalEndTag((string) end($stack))) {
                        return null;
                    }
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

        // EOF: omit optional end tags (html/body/p/…) like libxml htmlReadMemory (#20247).
        while ([] !== $stack && self::htmlElementHasOptionalEndTag((string) end($stack))) {
            array_pop($stack);
        }

        return [] === $stack ? $len : null;
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
        $tail = substr($content, $pos);
        if ('' === $tail) {
            return null;
        }

        if (preg_match('/<([A-Za-z_][\w:.-]*)(\s[^>]*)?(?=[^>]*(?:<|\z))/s', $tail, $broken)) {
            return strtolower($broken[1]);
        }

        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/is', $tail, $open)) {
            return null;
        }

        $tag = strtolower($open[1]);
        if (!preg_match('/<\/'.preg_quote($tag, '/').'\s*>/is', $tail)) {
            return $tag;
        }

        return null;
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

    private static function serializeHtmlNode(ObjectEntry $entry, bool $emptySelfClosing = true): string
    {
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
        if (self::isElement($entry)) {
            return self::serializeHtmlElement($entry, $emptySelfClosing);
        }
        if (self::isTextNode($entry)) {
            return DomRegistry::state($entry)->textContent ?? '';
        }
        if (self::isCommentNode($entry)) {
            return '<!--'.(DomRegistry::state($entry)->textContent ?? '').'-->';
        }

        throw new \DOMException('Cannot serialize node type in this compiler build');
    }

    private static function serializeHtmlElement(ObjectEntry $entry, bool $emptySelfClosing = true): string
    {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        $attrPart = self::serializeAttributes($state);
        if ([] === $state->childIds) {
            if ($emptySelfClosing) {
                return '<'.$name.$attrPart.'/>';
            }

            return '<'.$name.$attrPart.'></'.$name.'>';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeHtmlNode($child, $emptySelfClosing);
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
        bool $substituteEntities = false
    ): ?ObjectEntry {
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            $entry = self::createElement($ctx, $selfClose[1])->toObject();
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

        $entry = self::createElement($ctx, $matches[1])->toObject();
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
                    null,
                    $generalEntities,
                    $substituteEntities
                );
                $pos = false === $next ? $len : $next;

                continue;
            }
            $cdata = VmXml::parseCdataSectionAt($inner, $pos);
            if (null !== $cdata) {
                $cdataNode = self::createCdataSection($ctx, $cdata['data'], null);
                $state->childIds[] = $cdataNode->id;
                self::linkChildToParent($cdataNode, $entry);
                $pos = $cdata['end'];

                continue;
            }
            $comment = VmXml::parseCommentAt($inner, $pos);
            if (null !== $comment) {
                $commentNode = self::createComment($ctx, $comment['data'], null);
                $state->childIds[] = $commentNode->id;
                self::linkChildToParent($commentNode, $entry);
                $pos = $comment['end'];

                continue;
            }
            $pi = VmXml::parseProcessingInstructionAt($inner, $pos);
            if (null !== $pi) {
                $owner = self::ownerDocumentEntry($entry) ?? $entry;
                $piNode = self::createProcessingInstruction($ctx, $pi['target'], $pi['data'], $owner);
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
                $substituteEntities
            );
            if (null === $child) {
                return null;
            }
            $state->childIds[] = $child->id;
            self::linkChildToParent($child, $entry);
            self::resolveElementNamespaceUri($child);
            $pos = $end;
        }

        self::syncSubtree($ctx, $entry);

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

    private static function serializeNode(ObjectEntry $entry, int $depth = 0, bool $format = false, bool $noEmptyTag = false): string
    {
        if (self::isElement($entry)) {
            return self::serializeElement($entry, $depth, $format, $noEmptyTag);
        }
        if (self::isTextNode($entry)) {
            $text = self::escapeText(DomRegistry::state($entry)->textContent ?? '');
            if (!$format || '' === $text) {
                return $text;
            }

            return str_repeat('  ', $depth).$text;
        }
        if (self::isCdataNode($entry)) {
            return '<![CDATA['.(DomRegistry::state($entry)->textContent ?? '').']]>';
        }
        if (self::isCommentNode($entry)) {
            return '<!--'.(DomRegistry::state($entry)->textContent ?? '').'-->';
        }
        if (self::isProcessingInstruction($entry)) {
            $pi = DomRegistry::state($entry);

            return '<?'.$pi->nodeName.' '.($pi->textContent ?? '').'?>';
        }
        if (self::isEntityReference($entry)) {
            return '&'.self::escapeName(DomRegistry::state($entry)->nodeName).';';
        }

        throw new \DOMException('Cannot serialize node type in this compiler build');
    }

    private static function serializeElement(ObjectEntry $entry, int $depth = 0, bool $format = false, bool $noEmptyTag = false): string
    {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        $attrPart = self::serializeElementAttributes($entry);
        if ([] === $state->childIds) {
            $tag = $noEmptyTag
                ? '<'.$name.$attrPart.'></'.$name.'>'
                : '<'.$name.$attrPart.'/>';

            return $format ? str_repeat('  ', $depth).$tag : $tag;
        }
        if (!$format) {
            $parts = [];
            foreach ($state->childIds as $childId) {
                $child = DomRegistry::entry($childId);
                if (null !== $child) {
                    $parts[] = self::serializeNode($child, 0, false, $noEmptyTag);
                }
            }

            return '<'.$name.$attrPart.'>'.implode('', $parts).'</'.$name.'>';
        }

        $indent = str_repeat('  ', $depth);
        $lines = [$indent.'<'.$name.$attrPart.'>'];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $lines[] = self::serializeNode($child, $depth + 1, true, $noEmptyTag);
            }
        }
        $lines[] = $indent.'</'.$name.'>';

        return implode("\n", $lines);
    }

    /**
     * Emit attributes plus synthetic xmlns from createElementNS nsDef (php-src/libxml; #19397).
     *
     * @return non-empty-string|''
     */
    private static function serializeElementAttributes(ObjectEntry $entry): string
    {
        $state = DomRegistry::state($entry);
        $parts = [];
        foreach ($state->namespaceDeclarations as $prefix => $uri) {
            $attrName = '' === $prefix ? 'xmlns' : 'xmlns:'.$prefix;
            if (\array_key_exists($attrName, $state->attributes)) {
                continue;
            }
            if (self::parentNamespaceUri($entry, $prefix) === $uri) {
                continue;
            }
            $parts[] = self::escapeName($attrName).'="'.self::escapeAttr($uri).'"';
        }
        foreach ($state->attributes as $aname => $avalue) {
            $parts[] = self::escapeName($aname).'="'.self::escapeAttr($avalue).'"';
        }
        if ([] === $parts) {
            return '';
        }

        return ' '.implode(' ', $parts);
    }

    /** @return non-empty-string */
    private static function serializeAttributes(DomNodeState $state): string
    {
        if ([] === $state->attributes) {
            return '';
        }
        $parts = [];
        foreach ($state->attributes as $aname => $avalue) {
            $parts[] = self::escapeName($aname).'="'.self::escapeAttr($avalue).'"';
        }

        return ' '.implode(' ', $parts);
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

    public static function createLiveTagNameNodeList(
        Context $ctx,
        ObjectEntry $root,
        string $tagName
    ): Variable {
        $var = self::createNodeList($ctx, self::collectElementsByTagName($root, $tagName));
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
        $var = self::createNodeList(
            $ctx,
            self::collectElementsByTagNameNS($root, $namespaceUri, $localName)
        );
        $state = DomRegistry::state($var->toObject());
        $state->listQueryRootId = $root->id;
        $state->listQueryNamespaceUri = $namespaceUri;
        $state->listQueryLocalName = $localName;

        return $var;
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
        } else {
            return;
        }
        self::updateNodeListMembers($nodeList, $ids);
    }

    public static function isNodeList(ObjectEntry $entry): bool
    {
        return self::CLASS_NODE_LIST === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_NODELIST === DomRegistry::state($entry)->nodeType;
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
        $state = DomRegistry::state($namedNodeMap);
        foreach ($state->listNodeIds as $nodeId) {
            $node = DomRegistry::entry($nodeId);
            if (null === $node || !self::isAttr($node)) {
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
     * @param list<int> $nodeIds
     */
    public static function createNamedNodeMap(Context $ctx, array $nodeIds): Variable
    {
        $class = $ctx->classes[self::CLASS_NAMED_NODE_MAP] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMNamedNodeMap is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_LENGTH)->int(\count($nodeIds));

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_NAMEDNODEMAP;
        $state->nodeName = '#namednodemap';
        $state->listNodeIds = $nodeIds;
        $state->listIterIndex = 0;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function isNamedNodeMap(ObjectEntry $entry): bool
    {
        return self::CLASS_NAMED_NODE_MAP === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_NAMEDNODEMAP === DomRegistry::state($entry)->nodeType;
    }

    public static function createTokenList(Context $ctx, ObjectEntry $element): Variable
    {
        $class = $ctx->classes[self::CLASS_TOKEN_LIST] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMTokenList is not registered in this compiler build');
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
        return self::CLASS_TOKEN_LIST === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_TOKENLIST === DomRegistry::state($entry)->nodeType;
    }

    public static function isXPath(ObjectEntry $entry): bool
    {
        return self::CLASS_XPATH === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_XPATH === DomRegistry::state($entry)->nodeType;
    }

    public static function syncElementClassList(Context $ctx, ObjectEntry $element): void
    {
        if (!CompilerVersion::supportsDomTokenList() || !self::isElement($element)) {
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
        if (CompilerVersion::supportsDomTokenList() && !$entry->hasProperty(self::PROP_CLASS_LIST)) {
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
                    self::syncAttributeNodeValue($ctx, $cached, $value, true);
                    $ids[] = $cachedId;

                    continue;
                }
            }
            $attr = self::attributeNodeForElement($ctx, $element, $name, $value);
            $ids[] = $attr->id;
        }

        return $ids;
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

    private static function syncElementAttributes(Context $ctx, ObjectEntry $element): void
    {
        if (!self::isElement($element)) {
            return;
        }
        self::initElementPropertySlots($element);
        $state = DomRegistry::state($element);
        $attrIds = self::collectAttributeNodeIds($ctx, $element);
        self::syncAttributeTreeLinks($ctx, $element, $attrIds);

        $props = $element->propertiesWithNames();
        if (!isset($props[self::PROP_ATTRIBUTES])) {
            $element->allocateProperty(self::PROP_ATTRIBUTES);
            $props = $element->propertiesWithNames();
        }
        $attrsVar = $props[self::PROP_ATTRIBUTES];
        if (null !== $state->attributesListId) {
            $map = DomRegistry::entry($state->attributesListId);
            if (null !== $map) {
                self::updateNamedNodeMapMembers($map, $attrIds);
                $attrsVar->object($map);

                return;
            }
        }
        if (null === $state->attributesListId && Variable::TYPE_OBJECT === $attrsVar->resolveIndirect()->type) {
            $existing = $attrsVar->resolveIndirect()->toObject();
            if (self::isNamedNodeMap($existing)) {
                $state->attributesListId = $existing->id;
                self::updateNamedNodeMapMembers($existing, $attrIds);
                $attrsVar->object($existing);

                return;
            }
        }
        $mapVar = self::createNamedNodeMap($ctx, $attrIds);
        $map = $mapVar->toObject();
        $state->attributesListId = $map->id;
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
        ) {
            throw new \DOMException('Hierarchy request error');
        }
    }

    private static function assertChildOfParent(ObjectEntry $parent, ObjectEntry $child, string $label): void
    {
        if (!DomRegistry::has($child)) {
            throw new \DOMException('Not found error');
        }
        $childState = DomRegistry::state($child);
        if ($childState->parentId !== $parent->id) {
            throw new \DOMException('Not found error');
        }
        if (!\in_array($child->id, DomRegistry::state($parent)->childIds, true)) {
            throw new \DOMException('Not found error');
        }
    }

    private static function assertSameDocument(ObjectEntry $parent, ObjectEntry $child): void
    {
        $parentDocId = self::resolveDocumentId($parent);
        $childDocId = self::resolveDocumentId($child);
        if (null !== $parentDocId && null !== $childDocId && $parentDocId !== $childDocId) {
            throw new \DOMException('Wrong Document Error');
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
        $childNodesVar = $node->getProperty(self::PROP_CHILD_NODES)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $childNodesVar->type && self::isNodeList($childNodesVar->toObject())) {
            return;
        }
        self::syncNodeLinks($ctx, $node);
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

        $childNodesVar = $node->getProperty(self::PROP_CHILD_NODES);
        if (null !== $state->childNodesListId) {
            $list = DomRegistry::entry($state->childNodesListId);
            if (null !== $list) {
                self::updateNodeListMembers($list, $state->childIds);
                $childNodesVar->object($list);
                self::syncChildSiblingLinks($state->childIds);
                if (self::isElement($node)) {
                    self::syncElementAttributes($ctx, $node);
                }

                return;
            }
        }
        if (null === $state->childNodesListId && Variable::TYPE_OBJECT === $childNodesVar->resolveIndirect()->type) {
            $existing = $childNodesVar->resolveIndirect()->toObject();
            if (self::isNodeList($existing)) {
                $state->childNodesListId = $existing->id;
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
        $listVar = self::createNodeList($ctx, $state->childIds);
        $list = $listVar->toObject();
        $state->childNodesListId = $list->id;
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

        if (self::contains($node, $other)) {
            return DomConstants::DOCUMENT_POSITION_CONTAINS | DomConstants::DOCUMENT_POSITION_PRECEDING;
        }
        if (self::contains($other, $node)) {
            return DomConstants::DOCUMENT_POSITION_CONTAINED_BY | DomConstants::DOCUMENT_POSITION_FOLLOWING;
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
        if (DomConstants::XML_ELEMENT_NODE === $state->nodeType) {
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
        if (DomConstants::XML_ELEMENT_NODE !== $state->nodeType) {
            return;
        }
        $ownerDoc = self::ownerDocumentEntry($node);
        $state->childIds = [];
        if ('' !== $value) {
            $text = self::createTextNode($ctx, $value, $ownerDoc);
            $state->childIds[] = $text->id;
            self::linkChildToParent($text, $node);
        }
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

    public static function isDocument(ObjectEntry $entry): bool
    {
        return DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_NODE === DomRegistry::state($entry)->nodeType;
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
        if (!self::isDomNode($node)) {
            throw new \TypeError('DOMDocument::importNode(): Argument #1 ($importedNode) must be of type DOMNode');
        }
        $imported = self::importNodeEntry($ctx, $document, $node, $deep);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($imported);

        return $var;
    }

    /**
     * DOMDocument::adoptNode() — move node into this document (php-src ext/dom/document.c; #19654).
     *
     * Unlinks from the previous parent / owner element and updates ownerDocument. Does not
     * insert into the target tree (caller must appendChild / etc.).
     */
    public static function adoptNode(Context $ctx, ObjectEntry $document, ObjectEntry $node): Variable
    {
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
            $imported = self::createElement($ctx, $sourceState->nodeName)->toObject();
            self::linkChildToParent($imported, null);
            $importedState = DomRegistry::state($imported);
            $importedState->documentId = $document->id;
            $importedState->attributes = $sourceState->attributes;
            $importedState->attributeNamespaces = $sourceState->attributeNamespaces;
            $importedState->namespaceDeclarations = $sourceState->namespaceDeclarations;
            $importedState->localName = $sourceState->localName;
            $importedState->prefix = $sourceState->prefix;
            $importedState->namespaceUri = $sourceState->namespaceUri;
            $importedState->idAttributeName = $sourceState->idAttributeName;
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
            $imported = self::createDocumentFragment($ctx)->toObject();
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
            $clonedState->localName = $sourceState->localName;
            $clonedState->prefix = $sourceState->prefix;
            $clonedState->namespaceUri = $sourceState->namespaceUri;
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

    private static function serializeDoctype(string $name, string $publicId, string $systemId): string
    {
        if ('' !== $publicId || '' !== $systemId) {
            return sprintf(
                '<!DOCTYPE %s PUBLIC "%s" "%s">',
                self::escapeName($name),
                self::escapeAttr($publicId),
                self::escapeAttr($systemId)
            );
        }

        return '<!DOCTYPE '.self::escapeName($name).'>';
    }

    private static function escapeAttr(string $value): string
    {
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }

    private static function escapeText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
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
        if ('' === $name || !preg_match('/^[A-Za-z_:][\w.:-]*$/', $name)) {
            throw new \DOMException('Invalid Character Error', DomExceptionConstants::INVALID_CHARACTER_ERR);
        }
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
        $baseEntry = self::resolveClassByName($ctx, $baseClassName);
        if (null === $baseEntry) {
            throw new \TypeError(sprintf(
                'DOMDocument::registerNodeClass(): Argument #1 ($baseClass) must be a valid class name, %s given',
                $baseClassName
            ));
        }
        if (!InterfaceCheck::entryIsInstanceOf($baseEntry, self::CLASS_NODE, $ctx)) {
            throw new \TypeError(sprintf(
                'DOMDocument::registerNodeClass(): Argument #1 ($baseClass) must be a valid class name, %s given',
                $baseClassName
            ));
        }
        if ($baseEntry->isAbstract) {
            throw new \ValueError('DOMDocument::registerNodeClass(): Argument #1 ($baseClass) must not be an abstract class');
        }
        $baseLc = strtolower($baseEntry->name);
        if (null === $extendedClassName) {
            unset(DomRegistry::state($document)->nodeClassMap[$baseLc]);

            return;
        }
        $extendedEntry = self::resolveClassByName($ctx, $extendedClassName);
        if (null === $extendedEntry) {
            throw new \TypeError(sprintf(
                'DOMDocument::registerNodeClass(): Argument #2 ($extendedClass) must be a class name derived from %s or null, %s given',
                $baseEntry->name,
                $extendedClassName
            ));
        }
        if (!InterfaceCheck::entryIsInstanceOf($extendedEntry, $baseLc, $ctx)) {
            throw new \TypeError(sprintf(
                'DOMDocument::registerNodeClass(): Argument #2 ($extendedClass) must be a class name derived from %s or null, %s given',
                $baseEntry->name,
                $extendedClassName
            ));
        }
        if ($extendedEntry->isAbstract) {
            throw new \ValueError('DOMDocument::registerNodeClass(): Argument #2 ($extendedClass) must not be an abstract class');
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
        $extendedLc = DomRegistry::state($ownerDocument)->nodeClassMap[$baseLc] ?? null;
        if (null === $extendedLc) {
            return $default;
        }

        return $ctx->classes[$extendedLc] ?? $default;
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
            self::CLASS_DOCUMENT => 'DOMDocument',
            self::CLASS_DOCUMENT_TYPE => 'DOMDocumentType',
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
        $href = self::getAttribute($include, 'href');
        $parse = self::getAttribute($include, 'parse');
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
        $rootVar = $tmpDoc->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $rootVar->type) {
            self::replaceNodeWithNodes($ctx, $include, []);

            return 1;
        }
        $imported = self::importNodeEntry($ctx, $document, $rootVar->toObject(), true);
        self::replaceNodeWithNodes($ctx, $include, [$imported]);

        return 1;
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
                $state->doctypeSystemId ?? ''
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
        if ('' === $filename || !is_file($filename)) {
            $schemaPath = $filename;
            if ('' !== $schemaPath && '/' !== $schemaPath[0]) {
                $schemaPath = getcwd() . '/' . $schemaPath;
            }
            if ('' !== $schemaPath) {
                // Mirror libxml's missing-schema diagnostics closely enough for php-src parity.
                self::triggerDomWarning($frame, sprintf('I/O warning : failed to load external entity "%s"', $schemaPath));
                self::triggerDomWarning($frame, sprintf("Failed to locate the main schema resource at '%s'.", $schemaPath));
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
        if ('' === $filename || !is_file($filename)) {
            $rngPath = $filename;
            if ('' !== $rngPath && '/' !== $rngPath[0]) {
                $rngPath = getcwd() . '/' . $rngPath;
            }
            if ('' !== $rngPath) {
                // Mirror libxml's missing-RNG diagnostics closely enough for php-src parity.
                self::triggerDomWarning($frame, sprintf('I/O warning : failed to load external entity "%s"', $rngPath));
                self::triggerDomWarning($frame, sprintf('xmlRelaxNGParse: could not load %s', $rngPath));
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
     * @param ?array<mixed> $xpath
     * @param ?array<mixed> $nsPrefixes
     */
    public static function c14n(
        Context $ctx,
        ObjectEntry $node,
        bool $exclusive,
        bool $withComments,
        ?array $xpath,
        ?array $nsPrefixes
    ): string|false {
        if (null !== $xpath) {
            return self::c14nWithXPathNodeset($ctx, $node, $exclusive, $withComments, $xpath, $nsPrefixes);
        }
        unset($ctx, $nsPrefixes);
        if (!DomRegistry::has($node)) {
            return false;
        }
        $state = DomRegistry::state($node);
        if (DomConstants::XML_DOCUMENT_NODE === $state->nodeType) {
            $rootVar = $node->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $rootVar->type) {
                return '';
            }

            return self::c14nSerializeNode($rootVar->toObject(), $withComments, $exclusive, []);
        }
        // php-src zim_dom_node_C14N / xmlC14NDocDumpMemory: disconnected nodes → "" (#19741).
        if (!self::isConnected($node)) {
            return '';
        }

        return self::c14nSerializeNode($node, $withComments, $exclusive, []);
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
        unset($frame);
        $payload = self::c14n($ctx, $node, $exclusive, $withComments, $xpath, $nsPrefixes);
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
        if (self::isEntityReference($entry)) {
            return '&'.self::escapeName(DomRegistry::state($entry)->nodeName).';';
        }

        return false;
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

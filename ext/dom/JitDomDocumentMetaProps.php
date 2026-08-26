<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument meta props after loadXML (#34894).
 *
 * Thin AOT has no DomRegistry — undeclared PropertyFetch would
 * {@code defineProperty} an uninitialized external slot (SIGSEGV), same class as
 * {@see JitDomDocumentDoctype} / #34887. Pin slots in allocate() layout and return
 * computed boxes (never late-define).
 *
 * php-src: ext/dom/php_dom.c — dom_document_*_read
 *          ext/dom/document.c — document properties
 */
final class JitDomDocumentMetaProps
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_IMPLEMENTATION = 'DOMImplementation';

    /** @var list<string> */
    private const META_PROPS = [
        'xmlversion',
        'xmlencoding',
        'xmlstandalone',
        'documenturi',
        'implementation',
    ];

    public static function isDomDocumentMetaProp(string $classLc, string $propLc): bool
    {
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        $propLc = strtolower($propLc);
        if (!\in_array($propLc, self::META_PROPS, true)) {
            return false;
        }

        return 'domdocument' === $classLc
            || 'dom\\document' === $classLc
            || 'dom\\htmldocument' === $classLc
            || 'dom\\xmldocument' === $classLc;
    }

    public static function fetch(
        Object_ $objectType,
        Value $obj,
        string $className,
        string $propName,
        ?JITVariable $receiverVar = null
    ): JITVariable {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);
        $meta = $receiverVar->compileTimeDomXmlMeta ?? null;
        if (null === $meta) {
            // Named loadXML receivers get compileTimeDomXmlMeta stamped; temps may only
            // carry compileTimeDomLoadXml. Never use the global last-load for a Variable
            // that never loadXML'd (would leak URI onto `new DOMDocument()`; #34894).
            if (null !== $receiverVar && null !== ($receiverVar->compileTimeDomLoadXml ?? null)) {
                $declLast = JitDomLoadXMLUserScript::lastXmlDeclaration();
                $meta = [
                    'version' => $declLast['version'],
                    'encoding' => $declLast['encoding'],
                    'standalone' => $declLast['standalone'],
                    'documentUri' => JitDomLoadXMLUserScript::lastDocumentUri(),
                ];
            } elseif (null === $receiverVar && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
                $declLast = JitDomLoadXMLUserScript::lastXmlDeclaration();
                $meta = [
                    'version' => $declLast['version'],
                    'encoding' => $declLast['encoding'],
                    'standalone' => $declLast['standalone'],
                    'documentUri' => JitDomLoadXMLUserScript::lastDocumentUri(),
                ];
            }
        }
        $decl = null !== $meta
            ? [
                'version' => $meta['version'],
                'encoding' => $meta['encoding'],
                'standalone' => $meta['standalone'],
            ]
            : ['version' => '1.0', 'encoding' => null, 'standalone' => false];
        $loaded = null !== $meta;

        return match ($propLc) {
            'xmlversion' => self::boxString($context, $decl['version']),
            'xmlencoding' => null === $decl['encoding']
                ? self::boxNull($context)
                : self::boxString($context, $decl['encoding']),
            'xmlstandalone' => self::boxBool($context, $decl['standalone']),
            'documenturi' => $loaded
                ? self::boxString($context, $meta['documentUri'])
                : self::boxNull($context),
            'implementation' => self::boxImplementation($objectType),
            default => self::boxNull($context),
        };
    }

    /**
     * Initialize pinned meta slots after user-script loadXML (#34894).
     *
     * @param array{version: string, encoding: ?string, standalone: bool} $decl
     */
    public static function storeAfterLoadXml(
        \PHPCompiler\JIT\Context $context,
        Value $document,
        array $decl,
        string $documentUri
    ): void {
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        foreach ([
            VmDom::PROP_XML_VERSION,
            VmDom::PROP_XML_ENCODING,
            VmDom::PROP_XML_STANDALONE,
            VmDom::PROP_DOCUMENT_URI,
            VmDom::PROP_IMPLEMENTATION,
        ] as $prop) {
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        self::storeValueString($context, $document, VmDom::PROP_XML_VERSION, $decl['version']);
        if (null === $decl['encoding']) {
            self::storeValueNull($context, $document, VmDom::PROP_XML_ENCODING);
        } else {
            self::storeValueString($context, $document, VmDom::PROP_XML_ENCODING, $decl['encoding']);
        }
        self::storeValueBool($context, $document, VmDom::PROP_XML_STANDALONE, $decl['standalone']);
        self::storeValueString($context, $document, VmDom::PROP_DOCUMENT_URI, $documentUri);

        $impl = self::materializeImplementation($objectType);
        $implJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $impl
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_IMPLEMENTATION),
            $implJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function boxImplementation(Object_ $objectType): JITVariable
    {
        $context = $objectType->jitContext();
        // Fresh stand-in each fetch — php-src returns the Implementation singleton;
        // thin AOT only needs is_object / get_class (#34894).
        $impl = self::materializeImplementation($objectType);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $impl
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
    }

    private static function materializeImplementation(Object_ $objectType): Value
    {
        $context = $objectType->jitContext();
        $classId = $objectType->lookup(self::CLASS_IMPLEMENTATION);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    private static function storeValueString(
        \PHPCompiler\JIT\Context $context,
        Value $document,
        string $prop,
        string $lit
    ): void {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($document, self::CLASS_DOCUMENT, $prop),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    private static function storeValueNull(
        \PHPCompiler\JIT\Context $context,
        Value $document,
        string $prop
    ): void {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($document, self::CLASS_DOCUMENT, $prop),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    private static function storeValueBool(
        \PHPCompiler\JIT\Context $context,
        Value $document,
        string $prop,
        bool $value
    ): void {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt($value ? 1 : 0, false), $i32)
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($document, self::CLASS_DOCUMENT, $prop),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }

    private static function boxString(\PHPCompiler\JIT\Context $context, string $lit): JITVariable
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
    }

    private static function boxBool(\PHPCompiler\JIT\Context $context, bool $value): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt($value ? 1 : 0, false), $i32)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
    }

    private static function boxNull(\PHPCompiler\JIT\Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $slot
        );
    }
}

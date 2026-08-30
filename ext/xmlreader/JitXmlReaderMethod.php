<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for XMLReader instance/static methods — user-script AOT (#27299). */
final class JitXmlReaderMethod
{
    public static function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        $result = match ($methodLc) {
            'fromstring', 'xml' => JitXmlReaderUserScript::tryFromString($context, ...$args),
            // leftover of fromUri/fromString (#35907 / #27299) — php-src zim_xmlreader_open
            'open' => JitXmlReaderUserScript::tryOpen($context, ...$args),
            // leftover of fromString (#35900 / #27299) — php-src zim_xmlreader_fromUri
            'fromuri' => JitXmlReaderUserScript::tryFromUri($context, ...$args),
            // leftover of fromString (#35900 / #27299) — php-src zim_xmlreader_fromStream
            'fromstream' => JitXmlReaderUserScript::tryFromStream($context, ...$args),
            'read' => JitXmlReaderUserScript::tryRead($context, ...$args),
            // leftover of fromString read (#35908 / #27299) — php-src zim_XMLReader_readInnerXml
            'readinnerxml' => JitXmlReaderUserScript::tryReadInnerXml($context, ...$args),
            // leftover of fromString read (#35908 / #27299) — php-src zim_XMLReader_readOuterXml
            'readouterxml' => JitXmlReaderUserScript::tryReadOuterXml($context, ...$args),
            // leftover of fromString/readInnerXml (#35917 / #27299) — php-src zim_XMLReader_readString
            'readstring' => JitXmlReaderUserScript::tryReadString($context, ...$args),
            // leftover of fromString/open (#35911 / #27299) — php-src zim_XMLReader_expand
            'expand' => JitXmlReaderUserScript::tryExpand($context, ...$args),
            // leftover of fromString/read (#35918 / #27299) — php-src zim_XMLReader_getAttribute
            'getattribute' => JitXmlReaderUserScript::tryGetAttribute($context, ...$args),
            // leftover of getAttribute (#35924 / #35918 / #27299) — php-src zim_XMLReader_getAttributeNs
            'getattributens' => JitXmlReaderUserScript::tryGetAttributeNs($context, ...$args),
            // leftover of getAttribute (#35924 / #35918 / #27299) — php-src zim_XMLReader_getAttributeNo
            'getattributeno' => JitXmlReaderUserScript::tryGetAttributeNo($context, ...$args),
            // leftover of fromString/getAttribute (#35930 / #27299) — php-src zim_XMLReader_lookupNamespace
            'lookupnamespace' => JitXmlReaderUserScript::tryLookupNamespace($context, ...$args),
            // leftover of fromString/read (#35926 / #27299) — php-src zim_XMLReader_next
            'next' => JitXmlReaderUserScript::tryNext($context, ...$args),
            // leftover of fromString/read (#35959 / #27299) — php-src zim_XMLReader_isValid
            'isvalid' => JitXmlReaderUserScript::tryIsValid($context, ...$args),
            // leftover of fromString/open (#35935 / #27299) — php-src zim_XMLReader_close
            'close' => JitXmlReaderUserScript::tryClose($context, ...$args),
            // leftover of getAttribute (#35941 / #35918 / #27299) — php-src zim_XMLReader_moveToAttribute
            'movetoattribute' => JitXmlReaderUserScript::tryMoveToAttribute($context, ...$args),
            // leftover of moveToAttribute (#35946 / #35941 / #27299) — php-src zim_XMLReader_moveToAttributeNo
            'movetoattributeno' => JitXmlReaderUserScript::tryMoveToAttributeNo($context, ...$args),
            // leftover of moveToAttribute (#35948 / #35941 / #27299) — php-src zim_XMLReader_moveToFirstAttribute
            'movetofirstattribute' => JitXmlReaderUserScript::tryMoveToFirstAttribute($context, ...$args),
            // leftover of moveToAttribute (#35951 / #35941 / #27299) — php-src zim_XMLReader_moveToAttributeNs
            'movetoattributens' => JitXmlReaderUserScript::tryMoveToAttributeNs($context, ...$args),
            // leftover of moveToAttribute (#35940 / #27299) — php-src zim_XMLReader_moveToElement
            'movetoelement' => JitXmlReaderUserScript::tryMoveToElement($context, ...$args),
            // leftover of moveToAttribute (#35952 / #35941 / #27299) — php-src zim_XMLReader_moveToNextAttribute
            'movetonextattribute' => JitXmlReaderUserScript::tryMoveToNextAttribute($context, ...$args),
            default => null,
        };
        if (null === $result) {
            throw new \LogicException(
                'XMLReader::'.$methodLc.'() user-script AOT requires compile-time source + tracked reader (#27299)'
            );
        }

        return $result;
    }
}

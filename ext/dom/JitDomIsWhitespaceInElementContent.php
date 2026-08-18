<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMText::isWhitespaceInElementContent() (php-src xmlIsBlankNode).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry would abort. Fold compile-time blank-node check like
 * {@see JitDomSplitText}.
 *
 * php-src: ext/dom/text.c PHP_METHOD(DOMText, isWhitespaceInElementContent) (#32396)
 */
final class JitDomIsWhitespaceInElementContent
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iswhitespace_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMText::isWhitespaceInElementContent',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $data = $args[0]->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomSubstringData::$lastMaterializedData;
        if (null === $data) {
            throw new \LogicException(
                'DOMText::isWhitespaceInElementContent() user-script AOT requires compile-time data'
            );
        }

        return self::boxBoolResult($context, self::isBlankXmlText($data));
    }

    /** xmlIsBlankNode: empty or only XML whitespace (space, tab, LF, CR). */
    public static function isBlankXmlText(string $data): bool
    {
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

    private static function boxBoolResult(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}

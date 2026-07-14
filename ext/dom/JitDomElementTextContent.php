<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomElementTextContentRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMElement::$textContent on PHP-mutated nodes (#17954). */
final class JitDomElementTextContent
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_TEXT_CONTENT = 'textContent';

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        DomElementTextContentRuntime::ensureLinked($context);
        $str = $context->builder->call(
            $context->lookupFunction(DomElementTextContentRuntime::ABI_NAME),
            $obj
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $str
        );
    }

    public static function isDomElementTextContent(string $classLc, string $propLc): bool
    {
        return self::CLASS_ELEMENT === $classLc && 'textcontent' === $propLc;
    }

    public static function loadObjectFromReceiver(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOMElement::$textContent receiver must be an object');
    }
}

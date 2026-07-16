<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Shared wiring for xml_set_* handler registration (php-src ext/xml/xml.c; #18203, #19343).
 */
abstract class XmlSetHandlerFunction extends XmlFunction
{
    /** @param non-empty-string $slot */
    protected function setSingleHandler(Frame $frame, string $slot, int $handlerArgIndex): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], $this->getName(), 1);
        $handler = $this->resolveHandler($frame->calledArgs[$handlerArgIndex] ?? null);
        $frame->returnVar->bool(XmlParserHandlers::setHandler($parser, $slot, $handler));
    }

    protected function setDualHandler(Frame $frame, string $startSlot, string $endSlot, int $startIndex, int $endIndex): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], $this->getName(), 1);
        $start = $this->resolveHandler($frame->calledArgs[$startIndex] ?? null);
        $end = $this->resolveHandler($frame->calledArgs[$endIndex] ?? null);
        $ok = XmlParserHandlers::setHandler($parser, $startSlot, $start)
            && XmlParserHandlers::setHandler($parser, $endSlot, $end);
        $frame->returnVar->bool($ok);
    }

    /**
     * Resolve a SAX handler argument to a callable Variable (string name, Closure, or array).
     */
    protected function resolveHandler(?Variable $arg): ?Variable
    {
        if (null === $arg) {
            return null;
        }
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            $name = $arg->toString();
            if ('' === $name) {
                return null;
            }
            $out = new Variable();
            $out->string($name);

            return $out;
        }
        if (Variable::TYPE_OBJECT === $arg->type || Variable::TYPE_ARRAY === $arg->type) {
            $out = new Variable($arg->type);
            $out->copyFrom($arg);

            return $out;
        }
        // Scalar coercion — Zend converts non-callable scalars to string function names.
        $out = new Variable();
        $out->string($arg->toString());

        return $out;
    }
}

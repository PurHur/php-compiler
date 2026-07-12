<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Shared wiring for xml_set_* handler registration (php-src ext/xml/xml.c; #18203).
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
        $handler = $this->optionalStringHandler($frame->calledArgs[$handlerArgIndex] ?? null);
        $frame->returnVar->bool(XmlParserHandlers::setHandler($parser, $slot, $handler));
    }

    protected function setDualHandler(Frame $frame, string $startSlot, string $endSlot, int $startIndex, int $endIndex): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], $this->getName(), 1);
        $start = $this->optionalStringHandler($frame->calledArgs[$startIndex] ?? null);
        $end = $this->optionalStringHandler($frame->calledArgs[$endIndex] ?? null);
        $ok = XmlParserHandlers::setHandler($parser, $startSlot, $start)
            && XmlParserHandlers::setHandler($parser, $endSlot, $end);
        $frame->returnVar->bool($ok);
    }

    protected function optionalStringHandler(?Variable $arg): ?string
    {
        if (null === $arg) {
            return null;
        }
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return $arg->toString();
        }

        return (string) $arg->toString();
    }
}

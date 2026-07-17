<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * Build a DOM subtree from XMLReader event slices (php-src xmlTextReaderExpand + xmlDocCopyNode; #19394).
 */
final class XmlReaderExpandHelper
{
    /**
     * @param list<XmlReaderEvent> $events
     */
    public static function expandAt(
        Context $ctx,
        array $events,
        int $position,
        ?ObjectEntry $ownerDocument
    ): ObjectEntry|false {
        if ($position < 0 || $position >= \count($events)) {
            return false;
        }
        $event = $events[$position];

        return match ($event->nodeType) {
            XmlReaderConstants::ELEMENT => self::buildElement($ctx, $events, $position, $ownerDocument),
            XmlReaderConstants::END_ELEMENT => self::buildEmptyElement($ctx, $event, $ownerDocument),
            XmlReaderConstants::TEXT,
            XmlReaderConstants::WHITESPACE,
            XmlReaderConstants::SIGNIFICANT_WHITESPACE => VmDom::createTextNode(
                $ctx,
                $event->value,
                $ownerDocument
            ),
            XmlReaderConstants::CDATA => VmDom::createCdataSection($ctx, $event->value, $ownerDocument),
            XmlReaderConstants::COMMENT => VmDom::createComment($ctx, $event->value, $ownerDocument),
            default => false,
        };
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    private static function buildElement(
        Context $ctx,
        array $events,
        int $position,
        ?ObjectEntry $ownerDocument
    ): ObjectEntry {
        $event = $events[$position];
        $element = self::createElementFromEvent($ctx, $event, $ownerDocument);
        self::applyAttributes($ctx, $element, $event);
        if ($event->isEmptyElement) {
            return $element;
        }

        $depth = $event->depth;
        $i = $position + 1;
        $len = \count($events);
        while ($i < $len) {
            $child = $events[$i];
            if (XmlReaderConstants::END_ELEMENT === $child->nodeType && $child->depth === $depth) {
                break;
            }
            if (XmlReaderConstants::ELEMENT === $child->nodeType) {
                $childNode = self::buildElement($ctx, $events, $i, $ownerDocument);
                VmDom::appendChild($ctx, $element, $childNode);
                $i = self::skipElementSpan($events, $i);
                continue;
            }
            $leaf = self::expandAt($ctx, $events, $i, $ownerDocument);
            if ($leaf instanceof ObjectEntry) {
                VmDom::appendChild($ctx, $element, $leaf);
            }
            ++$i;
        }

        return $element;
    }

    private static function buildEmptyElement(
        Context $ctx,
        XmlReaderEvent $event,
        ?ObjectEntry $ownerDocument
    ): ObjectEntry {
        return self::createElementFromEvent($ctx, $event, $ownerDocument);
    }

    private static function createElementFromEvent(
        Context $ctx,
        XmlReaderEvent $event,
        ?ObjectEntry $ownerDocument
    ): ObjectEntry {
        if ('' !== $event->namespaceUri) {
            return VmDom::createElementNS(
                $ctx,
                $event->namespaceUri,
                $event->name,
                $ownerDocument
            )->toObject();
        }

        return VmDom::createElement($ctx, $event->name, $ownerDocument)->toObject();
    }

    private static function applyAttributes(Context $ctx, ObjectEntry $element, XmlReaderEvent $event): void
    {
        foreach ($event->attributes as $name => $value) {
            VmDom::setAttributeNS($ctx, $element, null, $name, $value);
        }
    }

    /**
     * Advance past an ELEMENT event and its descendants (to the index after matching END_ELEMENT,
     * or past a self-closing ELEMENT).
     *
     * @param list<XmlReaderEvent> $events
     */
    private static function skipElementSpan(array $events, int $position): int
    {
        $event = $events[$position];
        if ($event->isEmptyElement) {
            return $position + 1;
        }
        $depth = $event->depth;
        $i = $position + 1;
        $len = \count($events);
        while ($i < $len) {
            $cur = $events[$i];
            if (XmlReaderConstants::END_ELEMENT === $cur->nodeType && $cur->depth === $depth) {
                return $i + 1;
            }
            ++$i;
        }

        return $len;
    }
}

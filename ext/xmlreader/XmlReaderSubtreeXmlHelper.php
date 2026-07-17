<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

/**
 * Serialize XMLReader event slices as markup / text (php-src xmlTextReaderReadInnerXml /
 * ReadOuterXml / ReadString; #19411).
 */
final class XmlReaderSubtreeXmlHelper
{
    /**
     * @param list<XmlReaderEvent> $events
     */
    public static function outerXml(array $events, int $position): string
    {
        if ($position < 0 || $position >= \count($events)) {
            return '';
        }
        $event = $events[$position];

        return match ($event->nodeType) {
            XmlReaderConstants::ELEMENT => self::serializeElement($events, $position, true),
            XmlReaderConstants::END_ELEMENT => self::emptyElementTag($event, true),
            XmlReaderConstants::TEXT,
            XmlReaderConstants::WHITESPACE,
            XmlReaderConstants::SIGNIFICANT_WHITESPACE => $event->value,
            XmlReaderConstants::CDATA => '<![CDATA['.$event->value.']]>',
            XmlReaderConstants::COMMENT => '<!--'.$event->value.'-->',
            XmlReaderConstants::PI => self::serializePi($event),
            default => '',
        };
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    public static function innerXml(array $events, int $position): string
    {
        if ($position < 0 || $position >= \count($events)) {
            return '';
        }
        $event = $events[$position];
        if (XmlReaderConstants::ELEMENT !== $event->nodeType || $event->isEmptyElement) {
            return '';
        }

        $depth = $event->depth;
        $parts = [];
        $i = $position + 1;
        $len = \count($events);
        while ($i < $len) {
            $child = $events[$i];
            if (XmlReaderConstants::END_ELEMENT === $child->nodeType && $child->depth === $depth) {
                break;
            }
            if (XmlReaderConstants::ELEMENT === $child->nodeType) {
                $parts[] = self::serializeElement($events, $i, true);
                $i = self::skipElementSpan($events, $i);
                continue;
            }
            $parts[] = self::outerXml($events, $i);
            ++$i;
        }

        return implode('', $parts);
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    public static function readString(array $events, int $position): string
    {
        if ($position < 0 || $position >= \count($events)) {
            return '';
        }
        $event = $events[$position];

        return match ($event->nodeType) {
            XmlReaderConstants::TEXT,
            XmlReaderConstants::WHITESPACE,
            XmlReaderConstants::SIGNIFICANT_WHITESPACE => self::decodeXmlEntities($event->value),
            XmlReaderConstants::ELEMENT => self::collectElementText($events, $position),
            default => '',
        };
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    private static function serializeElement(array $events, int $position, bool $asFragmentRoot): string
    {
        $event = $events[$position];
        $open = self::startTag($event, $asFragmentRoot);
        if ($event->isEmptyElement) {
            return $open;
        }

        $depth = $event->depth;
        $parts = [];
        $i = $position + 1;
        $len = \count($events);
        while ($i < $len) {
            $child = $events[$i];
            if (XmlReaderConstants::END_ELEMENT === $child->nodeType && $child->depth === $depth) {
                break;
            }
            if (XmlReaderConstants::ELEMENT === $child->nodeType) {
                // Nested under a parent start-tag: do not re-emit inherited xmlns.
                $parts[] = self::serializeElement($events, $i, false);
                $i = self::skipElementSpan($events, $i);
                continue;
            }
            $parts[] = self::outerXml($events, $i);
            ++$i;
        }

        return $open.implode('', $parts).'</'.$event->name.'>';
    }

    private static function startTag(XmlReaderEvent $event, bool $asFragmentRoot): string
    {
        $attrs = $event->attributes;
        if ($asFragmentRoot) {
            foreach (self::fragmentNamespaceDecls($event) as $name => $value) {
                if (!\array_key_exists($name, $attrs)) {
                    $attrs[$name] = $value;
                }
            }
        }
        $attrPart = '';
        foreach ($attrs as $name => $value) {
            $attrPart .= ' '.$name.'="'.$value.'"';
        }
        if ($event->isEmptyElement) {
            return '<'.$event->name.$attrPart.'/>';
        }

        return '<'.$event->name.$attrPart.'>';
    }

    private static function emptyElementTag(XmlReaderEvent $event, bool $asFragmentRoot): string
    {
        $attrs = $event->attributes;
        if ($asFragmentRoot) {
            foreach (self::fragmentNamespaceDecls($event) as $name => $value) {
                if (!\array_key_exists($name, $attrs)) {
                    $attrs[$name] = $value;
                }
            }
        }
        $attrPart = '';
        foreach ($attrs as $name => $value) {
            $attrPart .= ' '.$name.'="'.$value.'"';
        }

        return '<'.$event->name.$attrPart.'/>';
    }

    /**
     * Namespace declarations required when this element is serialized as a fragment root
     * (php-src/libxml re-emits in-scope xmlns on ReadInnerXml/ReadOuterXml).
     *
     * @return array<string, string>
     */
    private static function fragmentNamespaceDecls(XmlReaderEvent $event): array
    {
        $needed = [];
        if ('' !== $event->prefix) {
            $attr = 'xmlns:'.$event->prefix;
            if ('' !== $event->namespaceUri && !\array_key_exists($attr, $event->attributes)) {
                $needed[$attr] = $event->namespaceUri;
            }
        } elseif ('' !== $event->namespaceUri && !\array_key_exists('xmlns', $event->attributes)) {
            $needed['xmlns'] = $event->namespaceUri;
        }
        foreach ($event->attributes as $name => $_) {
            if ('xmlns' === $name || str_starts_with($name, 'xmlns:')) {
                continue;
            }
            $colon = strpos($name, ':');
            if (false === $colon) {
                continue;
            }
            $prefix = substr($name, 0, $colon);
            if ('xml' === $prefix) {
                continue;
            }
            $attr = 'xmlns:'.$prefix;
            if (\array_key_exists($attr, $event->attributes) || \array_key_exists($attr, $needed)) {
                continue;
            }
            $uri = $event->nsScope[$prefix] ?? '';
            if ('' !== $uri) {
                $needed[$attr] = $uri;
            }
        }

        return $needed;
    }

    private static function serializePi(XmlReaderEvent $event): string
    {
        if ('' === $event->value) {
            return '<?'.$event->name.'?>';
        }

        return '<?'.$event->name.' '.$event->value.'?>';
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    private static function collectElementText(array $events, int $position): string
    {
        $event = $events[$position];
        if ($event->isEmptyElement) {
            return '';
        }
        $depth = $event->depth;
        $buf = '';
        $i = $position + 1;
        $len = \count($events);
        while ($i < $len) {
            $child = $events[$i];
            if (XmlReaderConstants::END_ELEMENT === $child->nodeType && $child->depth === $depth) {
                break;
            }
            if (XmlReaderConstants::TEXT === $child->nodeType
                || XmlReaderConstants::CDATA === $child->nodeType
                || XmlReaderConstants::WHITESPACE === $child->nodeType
                || XmlReaderConstants::SIGNIFICANT_WHITESPACE === $child->nodeType
            ) {
                $buf .= self::decodeXmlEntities($child->value);
            }
            ++$i;
        }

        return $buf;
    }

    private static function decodeXmlEntities(string $text): string
    {
        return html_entity_decode($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
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

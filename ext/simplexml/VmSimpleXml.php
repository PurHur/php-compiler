<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * SimpleXML tree + OOP API (php-src ext/simplexml/simplexml.c; #3338).
 */
final class VmSimpleXml
{
    public const CLASS_LC = 'simplexmlelement';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('SimpleXMLElement');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }

        $entry->methods['__get'] = new SimpleXmlElementGet();
        $entry->methodVisibility['__get'] = $pub;
        $entry->methods['__tostring'] = new SimpleXmlElementToString();
        $entry->methodVisibility['__tostring'] = $pub;
        $entry->methodNames['__tostring'] = '__toString';
        $entry->methods['offsetget'] = new SimpleXmlElementOffsetGet();
        $entry->methodVisibility['offsetget'] = $pub;
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methods['offsetexists'] = new SimpleXmlElementOffsetExists();
        $entry->methodVisibility['offsetexists'] = $pub;
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methods['count'] = new SimpleXmlElementCount();
        $entry->methodVisibility['count'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    public static function loadString(Context $ctx, string $data, ?Frame $frame = null): ?ObjectEntry
    {
        $trimmed = trim($data);
        if ('' === $trimmed) {
            self::warn($ctx, 'simplexml_load_string(): supplied argument cannot be empty', $frame);

            return null;
        }

        if (!VmXml::validateAndReport($ctx, $trimmed, $frame)) {
            return null;
        }

        $root = self::parseDocumentRoot($trimmed);
        if (null === $root) {
            self::warn($ctx, 'simplexml_load_string(): Entity: line 1: parser error', $frame);

            return null;
        }

        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('SimpleXMLElement is not registered in this compiler build');
        }

        return self::wrapNode($ctx, $class, $root);
    }

    public static function loadFile(Context $ctx, string $filename, ?Frame $frame = null): ?ObjectEntry
    {
        $contents = @file_get_contents($filename);
        if (false === $contents) {
            self::warn($ctx, 'simplexml_load_file(): Failed to open stream: No such file or directory', $frame);

            return null;
        }

        return self::loadString($ctx, $contents, $frame);
    }

    public static function requireElement(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be SimpleXMLElement, %s given', $label, $entry->class->name));
        }
        if (!SimpleXmlRegistry::has($entry)) {
            throw new \LogicException($label.'(): SimpleXMLElement has no node state');
        }

        return $entry;
    }

    public static function childByName(Context $ctx, ObjectEntry $entry, string $name): ObjectEntry
    {
        $elements = self::matchingElements($entry, $name);
        if ([] === $elements) {
            return self::wrapView($ctx, $entry->class, []);
        }
        if (1 === \count($elements)) {
            return self::wrapNode($ctx, $entry->class, $elements[0]);
        }

        return self::wrapView($ctx, $entry->class, $elements);
    }

    public static function offsetGet(Context $ctx, ObjectEntry $entry, Variable $offset): Variable
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            $elements = SimpleXmlRegistry::view($entry);
            if ($index < 0 || $index >= \count($elements)) {
                $result = new Variable();
                $result->null();

                return $result;
            }

            $result = new Variable();
            $result->object(self::wrapNode($ctx, $entry->class, $elements[$index]));

            return $result;
        }
        if (Variable::TYPE_STRING === $offset->type) {
            $name = $offset->toString();
            $state = SimpleXmlRegistry::state($entry);
            $value = $state->attributes[$name] ?? null;
            $result = new Variable();
            if (null === $value) {
                $result->null();
            } else {
                $result->string($value);
            }

            return $result;
        }

        throw new \TypeError('SimpleXMLElement::offsetGet(): Argument #1 ($offset) must be of type int|string');
    }

    public static function offsetExists(ObjectEntry $entry, Variable $offset): bool
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            $elements = SimpleXmlRegistry::view($entry);

            return $index >= 0 && $index < \count($elements);
        }
        if (Variable::TYPE_STRING === $offset->type) {
            $state = SimpleXmlRegistry::state($entry);

            return \array_key_exists($offset->toString(), $state->attributes);
        }

        return false;
    }

    public static function countElements(ObjectEntry $entry): int
    {
        return \count(SimpleXmlRegistry::view($entry));
    }

    public static function textContent(ObjectEntry $entry): string
    {
        if (SimpleXmlRegistry::isView($entry)) {
            $parts = [];
            foreach (SimpleXmlRegistry::view($entry) as $node) {
                $parts[] = $node->text;
            }

            return implode('', $parts);
        }

        return SimpleXmlRegistry::state($entry)->text;
    }

    /** @return list<SimpleXmlNodeState> */
    private static function matchingElements(ObjectEntry $entry, string $name): array
    {
        if (SimpleXmlRegistry::isView($entry)) {
            $out = [];
            foreach (SimpleXmlRegistry::view($entry) as $node) {
                if ($node->name === $name) {
                    $out[] = $node;
                }
            }

            return $out;
        }

        return SimpleXmlRegistry::state($entry)->elementsNamed($name);
    }

    private static function wrapNode(Context $ctx, ClassEntry $class, SimpleXmlNodeState $node): ObjectEntry
    {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        SimpleXmlRegistry::attach($entry, $node);

        return $entry;
    }

    /** @param list<SimpleXmlNodeState> $elements */
    private static function wrapView(Context $ctx, ClassEntry $class, array $elements): ObjectEntry
    {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        if ([] === $elements) {
            SimpleXmlRegistry::attach($entry, new SimpleXmlNodeState(''));
            SimpleXmlRegistry::attachView($entry, []);
        } else {
            SimpleXmlRegistry::attach($entry, $elements[0]);
            SimpleXmlRegistry::attachView($entry, $elements);
        }

        return $entry;
    }

    private static function parseDocumentRoot(string $xml): ?SimpleXmlNodeState
    {
        $elementXml = preg_replace('/<\?xml[^?]*\?>/', '', $xml) ?? $xml;
        $elementXml = trim($elementXml);

        return self::parseElementTree(trim($elementXml));
    }

    private static function parseElementTree(string $elementXml): ?SimpleXmlNodeState
    {
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            return new SimpleXmlNodeState(
                $selfClose[1],
                self::parseAttributes($selfClose[2] ?? ''),
            );
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            return null;
        }

        $node = new SimpleXmlNodeState($matches[1], self::parseAttributes($matches[2] ?? ''));
        $inner = $matches[3];
        $pos = 0;
        $len = \strlen($inner);
        $textBuffer = '';
        while ($pos < $len) {
            if ('<' !== $inner[$pos]) {
                $next = strpos($inner, '<', $pos);
                $chunk = false === $next ? substr($inner, $pos) : substr($inner, $pos, $next - $pos);
                $textBuffer .= $chunk;
                $pos = false === $next ? $len : $next;

                continue;
            }
            if ('' !== $textBuffer) {
                $node->text .= $textBuffer;
                $textBuffer = '';
            }
            $end = self::findElementEnd($inner, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($inner, $pos, $end - $pos);
            $child = self::parseElementTree($childXml);
            if (null === $child) {
                return null;
            }
            $node->children[] = $child;
            $pos = $end;
        }
        $node->text .= $textBuffer;

        return $node;
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
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $selfClose, 0, $scan)) {
                $scan += \strlen($selfClose[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $childOpen, 0, $scan)) {
                $stack[] = $childOpen[1];
                $scan += \strlen($childOpen[0]);

                continue;
            }

            ++$scan;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function parseAttributes(string $attrString): array
    {
        $attrs = [];
        if ('' === trim($attrString)) {
            return $attrs;
        }
        if (preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*(\"[^\"]*\"|\'[^\']*\')/', $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = substr($match[2], 1, -1);
                $attrs[$match[1]] = $value;
            }
        }

        return $attrs;
    }

    private static function warn(Context $ctx, string $message, ?Frame $frame): void
    {
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError($message, ErrorReporter::E_WARNING, $frame);
        }
    }
}

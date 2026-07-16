<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * XMLWriter streaming serializer — PHP-in-PHP (php-src ext/xmlwriter/php_xmlwriter.c; #6065, #19340).
 */
final class VmXmlWriter
{
    public const CLASS_LC = 'xmlwriter';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = \PHPCfg\Func::FLAG_PUBLIC;

        $entry = new ClassEntry('XMLWriter');
        $entry->methods['openmemory'] = new XmlWriterOpenMemory();
        $entry->methodVisibility['openmemory'] = $pub;
        $entry->methodNames['openmemory'] = 'openMemory';
        $entry->methods['openuri'] = new XmlWriterOpenURI();
        $entry->methodVisibility['openuri'] = $pub;
        $entry->methodNames['openuri'] = 'openURI';
        $entry->methods['setindent'] = new XmlWriterSetIndent();
        $entry->methodVisibility['setindent'] = $pub;
        $entry->methodNames['setindent'] = 'setIndent';
        $entry->methods['setindentstring'] = new XmlWriterSetIndentString();
        $entry->methodVisibility['setindentstring'] = $pub;
        $entry->methodNames['setindentstring'] = 'setIndentString';
        $entry->methods['startdocument'] = new XmlWriterStartDocument();
        $entry->methodVisibility['startdocument'] = $pub;
        $entry->methodNames['startdocument'] = 'startDocument';
        $entry->methods['startelement'] = new XmlWriterStartElement();
        $entry->methodVisibility['startelement'] = $pub;
        $entry->methodNames['startelement'] = 'startElement';
        $entry->methods['writeattribute'] = new XmlWriterWriteAttribute();
        $entry->methodVisibility['writeattribute'] = $pub;
        $entry->methodNames['writeattribute'] = 'writeAttribute';
        $entry->methods['writeelement'] = new XmlWriterWriteElement();
        $entry->methodVisibility['writeelement'] = $pub;
        $entry->methodNames['writeelement'] = 'writeElement';
        $entry->methods['writecdata'] = new XmlWriterWriteCData();
        $entry->methodVisibility['writecdata'] = $pub;
        $entry->methodNames['writecdata'] = 'writeCData';
        $entry->methods['writecomment'] = new XmlWriterWriteComment();
        $entry->methodVisibility['writecomment'] = $pub;
        $entry->methodNames['writecomment'] = 'writeComment';
        $entry->methods['text'] = new XmlWriterText();
        $entry->methodVisibility['text'] = $pub;
        $entry->methodNames['text'] = 'text';
        $entry->methods['endelement'] = new XmlWriterEndElement();
        $entry->methodVisibility['endelement'] = $pub;
        $entry->methodNames['endelement'] = 'endElement';
        $entry->methods['enddocument'] = new XmlWriterEndDocument();
        $entry->methodVisibility['enddocument'] = $pub;
        $entry->methodNames['enddocument'] = 'endDocument';
        $entry->methods['outputmemory'] = new XmlWriterOutputMemory();
        $entry->methodVisibility['outputmemory'] = $pub;
        $entry->methodNames['outputmemory'] = 'outputMemory';
        $entry->methods['flush'] = new XmlWriterFlush();
        $entry->methodVisibility['flush'] = $pub;
        $entry->methodNames['flush'] = 'flush';

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    public static function requireWriter(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XMLWriter, %s given', $label, $entry->class->name));
        }

        return $entry;
    }

    public static function ensureState(ObjectEntry $entry): XmlWriterState
    {
        if (!XmlWriterRegistry::has($entry)) {
            $state = new XmlWriterState();
            XmlWriterRegistry::attach($entry, $state);
        }

        return XmlWriterRegistry::state($entry);
    }

    public static function openMemory(ObjectEntry $entry): bool
    {
        $state = self::ensureState($entry);
        self::resetState($state, 'memory', null);

        return true;
    }

    public static function openURI(ObjectEntry $entry, string $uri): bool
    {
        $dir = \dirname($uri);
        if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
            return false;
        }
        if (is_file($uri) && !is_writable($uri)) {
            return false;
        }
        if (!is_file($uri) && '' !== $dir && '.' !== $dir && !is_writable($dir)) {
            return false;
        }

        $state = self::ensureState($entry);
        self::resetState($state, 'uri', $uri);

        return true;
    }

    public static function setIndent(ObjectEntry $entry, bool $enable): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::setIndent()');
        $state->indent = $enable;

        return true;
    }

    public static function setIndentString(ObjectEntry $entry, string $indentation): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::setIndentString()');
        $state->indentString = $indentation;

        return true;
    }

    public static function startDocument(
        ObjectEntry $entry,
        ?string $version = '1.0',
        ?string $encoding = null
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::startDocument()');
        if ($state->documentStarted) {
            return false;
        }
        $version = null === $version || '' === $version ? '1.0' : $version;
        $state->version = $version;
        $state->encoding = $encoding;
        $state->documentStarted = true;

        $decl = '<?xml version="'.$version.'"';
        if (null !== $encoding && '' !== $encoding) {
            $decl .= ' encoding="'.self::escapeAttribute($encoding).'"';
        }
        $state->buffer .= $decl."?>\n";

        return true;
    }

    public static function startElement(ObjectEntry $entry, string $name): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::startElement()');
        if (!self::isValidElementName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startElement(): Argument #1 ($name) must be a valid element name, %s given',
                var_export($name, true)
            ));
        }
        self::closeStartTagIfOpen($state);
        if ([] !== $state->elementStack) {
            if ($state->indent) {
                $parentIdx = \count($state->elementStack) - 1;
                $state->elementStack[$parentIdx]['hasIndentedChild'] = true;
                $state->buffer .= "\n".str_repeat($state->indentString, \count($state->elementStack));
            }
        }
        $state->elementStack[] = ['name' => $name, 'hasIndentedChild' => false];
        $state->buffer .= '<'.self::escapeElementName($name);
        $state->startTagOpen = true;

        return true;
    }

    public static function writeAttribute(ObjectEntry $entry, string $name, string $value): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeAttribute()');
        if (!$state->startTagOpen || [] === $state->elementStack) {
            return false;
        }
        if (!self::isValidAttributeName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writeAttribute(): Argument #1 ($name) must be a valid attribute name, %s given',
                var_export($name, true)
            ));
        }
        $state->buffer .= ' '.self::escapeElementName($name).'="'.self::escapeAttribute($value).'"';

        return true;
    }

    public static function writeElement(ObjectEntry $entry, string $name, ?string $content = null): bool
    {
        if (!self::startElement($entry, $name)) {
            return false;
        }
        if (null !== $content) {
            if (!self::text($entry, $content)) {
                return false;
            }
        }

        return self::endElement($entry);
    }

    public static function writeCData(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeCData()');
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<![CDATA['.$content.']]>';

        return true;
    }

    public static function writeComment(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeComment()');
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<!--'.$content.'-->';

        return true;
    }

    public static function text(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::text()');
        self::closeStartTagIfOpen($state);
        $state->buffer .= self::escapeText($content);

        return true;
    }

    public static function endElement(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endElement()');
        if ([] === $state->elementStack) {
            return false;
        }
        if ($state->startTagOpen) {
            $state->buffer .= '/>';
            array_pop($state->elementStack);
            $state->startTagOpen = false;

            return true;
        }
        $frame = array_pop($state->elementStack);
        if ($frame['hasIndentedChild']) {
            $state->buffer .= "\n".str_repeat($state->indentString, \count($state->elementStack));
            $state->buffer .= '</'.self::escapeElementName($frame['name']).'>'."\n";
        } else {
            $state->buffer .= '</'.self::escapeElementName($frame['name']).'>';
        }

        return true;
    }

    public static function endDocument(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endDocument()');
        while ([] !== $state->elementStack) {
            self::endElement($entry);
        }
        // php-src/libxml xmlTextWriterEndDocument appends a trailing newline.
        if (!str_ends_with($state->buffer, "\n")) {
            $state->buffer .= "\n";
        }
        if ('uri' === $state->mode && null !== $state->uri) {
            if (false === @file_put_contents($state->uri, $state->buffer)) {
                return false;
            }
        }

        return true;
    }

    public static function outputMemory(ObjectEntry $entry, bool $flush = true): string
    {
        $state = self::requireOpen($entry, 'XMLWriter::outputMemory()');
        if ('memory' !== $state->mode) {
            return '';
        }
        $out = $state->buffer;
        if ($flush) {
            $state->buffer = '';
        }

        return $out;
    }

    public static function flush(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::flush()');
        if ('uri' !== $state->mode || null === $state->uri) {
            return false;
        }
        if ('' === $state->buffer) {
            return true;
        }
        $written = @file_put_contents($state->uri, $state->buffer, \FILE_APPEND);
        if (false === $written) {
            return false;
        }
        $state->buffer = '';

        return true;
    }

    private static function resetState(XmlWriterState $state, string $mode, ?string $uri): void
    {
        $state->open = true;
        $state->mode = $mode;
        $state->uri = $uri;
        $state->buffer = '';
        $state->elementStack = [];
        $state->startTagOpen = false;
        $state->documentStarted = false;
        $state->version = null;
        $state->encoding = null;
        // indent flags persist across openMemory in Zend? Reset for clean writers.
        $state->indent = false;
        $state->indentString = ' ';
    }

    private static function requireOpen(ObjectEntry $entry, string $label): XmlWriterState
    {
        if (!XmlWriterRegistry::has($entry)) {
            throw new \Error('Invalid or uninitialized XMLWriter object');
        }
        $state = XmlWriterRegistry::state($entry);
        if (!$state->open) {
            throw new \Error('Invalid or uninitialized XMLWriter object');
        }

        return $state;
    }

    private static function closeStartTagIfOpen(XmlWriterState $state): void
    {
        if ($state->startTagOpen) {
            $state->buffer .= '>';
            $state->startTagOpen = false;
        }
    }

    private static function isValidElementName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9._:-]*$/', $name)) {
            return false;
        }

        return true;
    }

    private static function isValidAttributeName(string $name): bool
    {
        return self::isValidElementName($name);
    }

    private static function escapeElementName(string $name): string
    {
        return $name;
    }

    private static function escapeAttribute(string $value): string
    {
        return str_replace(
            ['&', '"', '<'],
            ['&amp;', '&quot;', '&lt;'],
            $value
        );
    }

    private static function escapeText(string $value): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $value
        );
    }

    public static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Invoke registered SAX handlers during xml_parse() (#18203, #19683, #20333, php-src ext/xml/xml.c).
 *
 * Namespace-aware parsers (xml_parser_create_ns) expand element/attribute names as
 * uri + separator + localname and strip xmlns declarations from attribute bags.
 *
 * Default handler (xml_set_default_handler / XML_SetDefaultHandler) receives:
 * - Comment and (when no PI handler) processing-instruction markup as raw text
 * - Start/end tag markup when the corresponding element handler is unset
 * - Character data when the character-data handler is unset
 * (php-src ext/xml/compat.c comment_handler / pi_handler / start_element_handler)
 */
final class VmXmlSaxDispatcher
{
    public static function dispatch(
        Context $ctx,
        ObjectEntry $parser,
        string $data,
        ?Frame $frame = null
    ): void {
        $state = XmlParserHandlers::parserState($parser);
        if (null === $state) {
            return;
        }
        if (!self::hasAnyHandler($state)) {
            return;
        }

        $dispatcher = new self($ctx, $parser, $state, $frame);
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return;
        }
        // Keep Comment/PI Misc for default/PI handlers; only drop xml decl + DOCTYPE (#20333).
        $trimmed = VmXml::stripXmlDeclAndDoctypeKeepMisc($trimmed);
        if ('' === $trimmed) {
            return;
        }
        $dispatcher->parseDocument($trimmed);
    }

    /**
     * Stream SAX events as complete tokens appear in the accumulated buffer (#24657).
     *
     * Start-element handlers fire when a full start tag is buffered (Expat), without waiting
     * for the matching end tag or document well-formedness. Character data is held until the
     * next markup boundary or $isFinal (matches libxml-compat Zend coalescing).
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public static function dispatchIncremental(
        Context $ctx,
        ObjectEntry $parser,
        array $state,
        bool $isFinal,
        ?Frame $frame = null
    ): array {
        if (!self::hasAnyHandler($state)) {
            return $state;
        }

        $buffer = (string) ($state['buffer'] ?? '');
        if ('' === $buffer && !$isFinal) {
            return $state;
        }

        $dispatcher = new self($ctx, $parser, $state, $frame);
        $dispatcher->nsBindings = $state['saxNsBindings'] ?? ['' => ''];
        /** @var list<array{rawTag: string, tag: string, endMarkup: string, nsBindings: array<string, string>}> $openStack */
        $openStack = $state['saxOpenStack'] ?? [];
        $pos = (int) ($state['saxConsumed'] ?? 0);
        $pending = (string) ($state['saxPendingCdata'] ?? '');

        // Strip XML decl / DOCTYPE once at the start of the stream (#20333).
        if (0 === $pos && '' === $pending && [] === $openStack) {
            $stripped = VmXml::stripXmlDeclAndDoctypeKeepMisc(ltrim($buffer));
            if ($stripped !== $buffer) {
                // Re-base: buffer keeps original for validateWellFormed; SAX scans stripped view
                // via advancing past the prefix length in the original when possible.
                $prefixLen = \strlen($buffer) - \strlen(ltrim($buffer));
                $declStripped = VmXml::stripXmlDeclAndDoctypeKeepMisc(substr($buffer, $prefixLen));
                $removed = \strlen(substr($buffer, $prefixLen)) - \strlen($declStripped);
                $pos = $prefixLen + $removed;
            }
        }

        $len = \strlen($buffer);
        while ($pos < $len) {
            $depth = \count($openStack);
            if (0 === $depth) {
                $pos = self::skipWhitespace($buffer, $pos);
                if ($pos >= $len) {
                    break;
                }
            }

            if ('<' !== $buffer[$pos]) {
                $textEnd = strpos($buffer, '<', $pos);
                if (false === $textEnd) {
                    $pending .= substr($buffer, $pos);
                    $pos = $len;
                    break;
                }
                $pending .= substr($buffer, $pos, $textEnd - $pos);
                $pos = $textEnd;
                if ('' !== $pending && $depth > 0) {
                    $dispatcher->invokeCharacterData($pending);
                    $pending = '';
                }

                continue;
            }

            $comment = VmXml::parseCommentAt($buffer, $pos);
            if (null !== $comment) {
                if ('' !== $pending && $depth > 0) {
                    $dispatcher->invokeCharacterData($pending);
                    $pending = '';
                }
                $raw = substr($buffer, $pos, $comment['end'] - $pos);
                $dispatcher->invokeDefault($raw);
                $pos = $comment['end'];

                continue;
            }
            // Incomplete comment — wait for more input.
            if (str_starts_with(substr($buffer, $pos), '<!--') && false === strpos($buffer, '-->', $pos)) {
                break;
            }

            $pi = VmXml::parseProcessingInstructionAt($buffer, $pos);
            if (null !== $pi) {
                if ('' !== $pending && $depth > 0) {
                    $dispatcher->invokeCharacterData($pending);
                    $pending = '';
                }
                $raw = substr($buffer, $pos, $pi['end'] - $pos);
                $dispatcher->invokeProcessingInstruction($raw, $pi['target'], $pi['data']);
                $pos = $pi['end'];

                continue;
            }
            if (str_starts_with(substr($buffer, $pos), '<?') && false === strpos($buffer, '?>', $pos)) {
                break;
            }

            $cdata = VmXml::parseCdataSectionAt($buffer, $pos);
            if (null !== $cdata) {
                if ('' !== $pending && $depth > 0) {
                    $dispatcher->invokeCharacterData($pending);
                    $pending = '';
                }
                $dispatcher->invokeCharacterData($cdata['data']);
                $pos = $cdata['end'];

                continue;
            }
            if (str_starts_with(substr($buffer, $pos), '<![CDATA[') && false === strpos($buffer, ']]>', $pos)) {
                break;
            }

            // End tag.
            if ($pos + 1 < $len && '/' === $buffer[$pos + 1]) {
                if (!preg_match('/\G<\/([A-Za-z_][\w:.-]*)\s*>/s', $buffer, $close, 0, $pos)) {
                    break; // incomplete end tag
                }
                if ('' !== $pending && $depth > 0) {
                    $dispatcher->invokeCharacterData($pending);
                    $pending = '';
                }
                if ([] === $openStack) {
                    break;
                }
                $frameOpen = array_pop($openStack);
                $dispatcher->nsBindings = $frameOpen['nsBindings'];
                $dispatcher->invokeElementEnd($frameOpen['tag'], $frameOpen['endMarkup']);
                $pos += \strlen($close[0]);

                continue;
            }

            // Start / empty-element tag.
            if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?(\/?)>/s', $buffer, $open, 0, $pos)) {
                break; // incomplete start tag
            }
            if ('' !== $pending && $depth > 0) {
                $dispatcher->invokeCharacterData($pending);
                $pending = '';
            }

            $rawTag = $open[1];
            $attrSpec = $open[2] ?? '';
            $selfClose = isset($open[3]) && '/' === $open[3];
            $savedBindings = $dispatcher->nsBindings;
            $dispatcher->applyNamespaceDeclarations($attrSpec);
            $attrs = $dispatcher->attributesForHandlers($attrSpec);
            $tag = $dispatcher->expandElementName($rawTag);
            $startMarkup = '<'.$rawTag.$attrSpec.'>';
            $endMarkup = '</'.$rawTag.'>';
            $dispatcher->invokeElementStart($tag, $attrs, $startMarkup);
            $pos += \strlen($open[0]);

            if ($selfClose) {
                $dispatcher->invokeElementEnd($tag, $endMarkup);
                $dispatcher->nsBindings = $savedBindings;
            } else {
                $openStack[] = [
                    'rawTag' => $rawTag,
                    'tag' => $tag,
                    'endMarkup' => $endMarkup,
                    'nsBindings' => $savedBindings,
                ];
            }
        }

        if ($isFinal && '' !== $pending) {
            $dispatcher->invokeCharacterData($pending);
            $pending = '';
        }

        $state['saxConsumed'] = $pos;
        $state['saxPendingCdata'] = $pending;
        $state['saxNsBindings'] = $dispatcher->nsBindings;
        $state['saxOpenStack'] = $openStack;
        if ($isFinal || ([] === $openStack && $pos >= $len && '' === $pending)) {
            $state['saxDispatched'] = true;
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private static function hasAnyHandler(array $state): bool
    {
        foreach ($state['handlers'] as $handler) {
            if (null !== $handler) {
                return true;
            }
        }

        return false;
    }

    private Context $ctx;

    private ObjectEntry $parser;

    /** @var array<string, mixed> */
    private array $state;

    private ?Frame $frame;

    private Variable $parserVar;

    /** prefix => uri; empty-string key is the default namespace */
    /** @var array<string, string> */
    private array $nsBindings = ['' => ''];

    /** @param array<string, mixed> $state */
    private function __construct(Context $ctx, ObjectEntry $parser, array $state, ?Frame $frame)
    {
        $this->ctx = $ctx;
        $this->parser = $parser;
        $this->state = $state;
        $this->frame = $frame;
        $this->parserVar = new Variable(Variable::TYPE_OBJECT);
        $this->parserVar->object($parser);
    }

    /**
     * Document production: (Misc* element Misc*) — fire handlers for leading/trailing Misc (#20333).
     */
    private function parseDocument(string $data): void
    {
        $pos = 0;
        $len = \strlen($data);
        while ($pos < $len) {
            $pos = self::skipWhitespace($data, $pos);
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $data[$pos]) {
                break;
            }
            $comment = VmXml::parseCommentAt($data, $pos);
            if (null !== $comment) {
                $raw = substr($data, $pos, $comment['end'] - $pos);
                $this->invokeDefault($raw);
                $pos = $comment['end'];

                continue;
            }
            $pi = VmXml::parseProcessingInstructionAt($data, $pos);
            if (null !== $pi) {
                $raw = substr($data, $pos, $pi['end'] - $pos);
                $this->invokeProcessingInstruction($raw, $pi['target'], $pi['data']);
                $pos = $pi['end'];

                continue;
            }
            $next = $this->parseElementAt($data, $pos);
            if ($next === $pos) {
                break;
            }
            $pos = $next;
        }
    }

    private function parseElementAt(string $data, int $pos): int
    {
        $pos = self::skipWhitespace($data, $pos);
        if ($pos >= \strlen($data) || '<' !== $data[$pos]) {
            return $pos;
        }

        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?(\/?)>/s', $data, $open, 0, $pos)) {
            return $pos;
        }

        $rawTag = $open[1];
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $savedBindings = $this->nsBindings;
        // Apply xmlns + fire start-NS before element start (expat / php-src ext/xml/xml.c).
        $this->applyNamespaceDeclarations($attrSpec);
        $attrs = $this->attributesForHandlers($attrSpec);
        $tag = $this->expandElementName($rawTag);
        // Default-handler start markup reconstructs open form (self-close → start+end; compat.c).
        $startMarkup = '<'.$rawTag.$attrSpec.'>';
        $endMarkup = '</'.$rawTag.'>';
        $contentStart = $pos + \strlen($open[0]);

        $this->invokeElementStart($tag, $attrs, $startMarkup);

        if ($selfClose) {
            $this->invokeElementEnd($tag, $endMarkup);
            $this->nsBindings = $savedBindings;

            return $contentStart;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
            $this->nsBindings = $savedBindings;

            return $contentStart;
        }

        $innerEnd = $end - \strlen('</'.$open[1].'>');
        $scan = $contentStart;
        while ($scan < $innerEnd) {
            // Do not skip whitespace — it is character data for cdata/default handlers (#20333).
            if ('<' !== $data[$scan]) {
                $textEnd = strpos($data, '<', $scan);
                if (false === $textEnd || $textEnd > $innerEnd) {
                    $textEnd = $innerEnd;
                }
                $text = substr($data, $scan, $textEnd - $scan);
                if ('' !== $text) {
                    $this->invokeCharacterData($text);
                }
                $scan = $textEnd;

                continue;
            }
            $cdata = VmXml::parseCdataSectionAt($data, $scan);
            if (null !== $cdata) {
                $this->invokeCharacterData($cdata['data']);
                $scan = $cdata['end'];

                continue;
            }
            $comment = VmXml::parseCommentAt($data, $scan);
            if (null !== $comment) {
                $raw = substr($data, $scan, $comment['end'] - $scan);
                $this->invokeDefault($raw);
                $scan = $comment['end'];

                continue;
            }
            $pi = VmXml::parseProcessingInstructionAt($data, $scan);
            if (null !== $pi) {
                $raw = substr($data, $scan, $pi['end'] - $scan);
                $this->invokeProcessingInstruction($raw, $pi['target'], $pi['data']);
                $scan = $pi['end'];

                continue;
            }
            $scan = $this->parseElementAt($data, $scan);
        }

        $this->invokeElementEnd($tag, $endMarkup);
        // libxml-backed php-src does not invoke end-NS handlers (php.net); match Zend (#20323).
        $this->nsBindings = $savedBindings;

        return $end;
    }

    private function invokeElementStart(string $tag, HashTable $attrs, string $startMarkup): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_ELEMENT_START] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null !== $callback) {
            $nameVar = new Variable();
            $nameVar->string($tag);
            $attrsVar = new Variable();
            $attrsVar->array($attrs);
            VmCallable::invoke($this->ctx, $callback, $this->parserVar, $nameVar, $attrsVar);

            return;
        }
        $this->invokeDefault($startMarkup);
    }

    private function invokeElementEnd(string $tag, string $endMarkup): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_ELEMENT_END] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null !== $callback) {
            $nameVar = new Variable();
            $nameVar->string($tag);
            VmCallable::invoke($this->ctx, $callback, $this->parserVar, $nameVar);

            return;
        }
        $this->invokeDefault($endMarkup);
    }

    private function invokeCharacterData(string $text): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_CHARACTER_DATA] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null !== $callback) {
            $dataVar = new Variable();
            $dataVar->string($text);
            VmCallable::invoke($this->ctx, $callback, $this->parserVar, $dataVar);

            return;
        }
        $this->invokeDefault($text);
    }

    private function invokeProcessingInstruction(string $raw, string $target, string $data): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_PI] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null !== $callback) {
            $targetVar = new Variable();
            $targetVar->string($target);
            $dataVar = new Variable();
            $dataVar->string($data);
            VmCallable::invoke($this->ctx, $callback, $this->parserVar, $targetVar, $dataVar);

            return;
        }
        $this->invokeDefault($raw);
    }

    private function invokeDefault(string $data): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_DEFAULT] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null === $callback) {
            return;
        }
        $dataVar = new Variable();
        $dataVar->string($data);
        VmCallable::invoke($this->ctx, $callback, $this->parserVar, $dataVar);
    }

    private function nsAware(): bool
    {
        return !empty($this->state['nsAware']);
    }

    private function nsSeparator(): string
    {
        return (string) ($this->state['nsSeparator'] ?? ':');
    }

    private function foldTag(string $tag): string
    {
        return $this->caseFolding() ? strtoupper($tag) : $tag;
    }

    private function caseFolding(): bool
    {
        return 0 !== ($this->state['options'][XmlConstants::XML_OPTION_CASE_FOLDING] ?? 1);
    }

    /**
     * Apply xmlns / xmlns:prefix to the binding stack and invoke start-NS handlers
     * in document order (before the start-element handler; #20323 / php-src xml.c).
     *
     * End-NS is intentionally not dispatched: libxml-backed Zend never fires
     * xml_set_end_namespace_decl_handler (php.net), so php-src-strict matches empty end.
     */
    private function applyNamespaceDeclarations(string $attrSpec): void
    {
        if (!$this->nsAware()) {
            return;
        }
        foreach (self::parseAttributePairs($attrSpec) as $name => $value) {
            if ('xmlns' === $name) {
                $this->nsBindings[''] = $value;
                $this->invokeStartNamespaceDecl(false, $value);
            } elseif (str_starts_with($name, 'xmlns:')) {
                $prefix = substr($name, 6);
                $this->nsBindings[$prefix] = $value;
                $this->invokeStartNamespaceDecl($prefix, $value);
            }
        }
    }

    /**
     * Parse attributes, expand names when NS-aware, and omit xmlns declarations from
     * the handler attribute bag (expat NS mode). Bindings must already be applied.
     */
    private function attributesForHandlers(string $attrSpec): HashTable
    {
        $parsed = self::parseAttributePairs($attrSpec);
        $attrs = new HashTable();
        $fold = $this->caseFolding();
        foreach ($parsed as $name => $value) {
            if ($this->nsAware() && ('xmlns' === $name || str_starts_with($name, 'xmlns:'))) {
                continue;
            }
            $expanded = $this->nsAware() ? $this->expandAttributeName($name) : $name;
            $outName = $fold ? strtoupper($expanded) : $expanded;
            $val = new Variable();
            $val->string($value);
            $attrs->add($outName, $val);
        }

        return $attrs;
    }

    /** @param string|false $prefix */
    private function invokeStartNamespaceDecl($prefix, string $uri): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_START_NS] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null === $callback) {
            return;
        }
        $prefixVar = new Variable();
        if (false === $prefix) {
            $prefixVar->bool(false);
        } else {
            $prefixVar->string($prefix);
        }
        $uriVar = new Variable();
        $uriVar->string($uri);
        VmCallable::invoke($this->ctx, $callback, $this->parserVar, $prefixVar, $uriVar);
    }

    private function expandElementName(string $rawTag): string
    {
        if (!$this->nsAware()) {
            return $this->foldTag($rawTag);
        }
        $expanded = $this->expandQName($rawTag, true);

        return $this->foldTag($expanded);
    }

    private function expandAttributeName(string $rawName): string
    {
        return $this->expandQName($rawName, false);
    }

    /**
     * Expand a QName to uri+sep+local (or local when unbound / no URI).
     * Element names use the default namespace; attribute names do not (#19683 / expat).
     */
    private function expandQName(string $qname, bool $isElement): string
    {
        $colon = strpos($qname, ':');
        if (false !== $colon && 0 !== $colon) {
            $prefix = substr($qname, 0, $colon);
            $local = substr($qname, $colon + 1);
            $uri = $this->nsBindings[$prefix] ?? '';
            if ('' !== $uri) {
                return $uri.$this->nsSeparator().$local;
            }

            return $qname;
        }
        if ($isElement) {
            $uri = $this->nsBindings[''] ?? '';
            if ('' !== $uri) {
                return $uri.$this->nsSeparator().$qname;
            }
        }

        return $qname;
    }

    /** @return array<string, string> */
    private static function parseAttributePairs(string $attrSpec): array
    {
        $pairs = [];
        if ('' === trim($attrSpec)) {
            return $pairs;
        }
        if (preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/s', $attrSpec, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = '' !== ($match[2] ?? '') ? $match[2] : ('' !== ($match[3] ?? '') ? $match[3] : ($match[4] ?? ''));
                $pairs[$match[1]] = $value;
            }
        }

        return $pairs;
    }

    private static function skipWhitespace(string $data, int $pos): int
    {
        $len = \strlen($data);
        while ($pos < $len && ctype_space($data[$pos])) {
            ++$pos;
        }

        return $pos;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: compile-time XMLWriter via host ext/xmlwriter (#19551).
 *
 * Tracks writers constructed in the user script so method calls with compile-time
 * literal args can be applied on the host and results (bools/strings) emitted as constants.
 */
final class JitXmlWriterUserScript
{
    /** @var \SplObjectStorage<JITVariable, \XMLWriter>|null */
    private static ?\SplObjectStorage $writers = null;

    /** @var array<string, \XMLWriter> */
    private static array $writersByToken = [];

    private static ?\XMLWriter $lastWriter = null;

    private static int $tokenSeq = 0;

    /** Last compile-time fopen() path — recovers URI for toStream fold (#35895). */
    private static ?string $lastFopenPath = null;

    /** @var array<string, string> stream compileTimeString token → fopen path */
    private static array $fopenPathsByToken = [];

    public static function isUserScriptAot(): bool
    {
        return \PHPCompiler\JIT\UserScriptAotEnv::isActive();
    }

    /**
     * Record a compile-time fopen() path so XMLWriter::toStream can openUri (#35895).
     */
    public static function noteFopenPath(string $path, ?JITVariable $streamResult = null): void
    {
        if ('' === $path || str_starts_with($path, '__phpc_')) {
            return;
        }
        self::$lastFopenPath = $path;
        if (null !== $streamResult) {
            $token = $streamResult->compileTimeString;
            if (null === $token || str_starts_with($token, '__phpc_xw_')) {
                $token = '__phpc_fopen_'.(++self::$tokenSeq);
                $streamResult->compileTimeString = $token;
            }
            self::$fopenPathsByToken[$token] = $path;
        }
    }

    public static function tryInit(Context $context, JITVariable $receiver): ?Value
    {
        if (!\extension_loaded('xmlwriter') || !\class_exists(\XMLWriter::class, false)) {
            return null;
        }
        $writer = new \XMLWriter();
        self::store($receiver, $writer);

        return self::nullValue($context);
    }

    public static function tryOpenMemory(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->openMemory();

        return self::boolValue($context, $ok);
    }

    /**
     * XMLWriter::toMemory() leftover of openMemory (#19606 / #35872).
     * php-src: zim_XMLWriter_toMemory — static factory = new + xmlTextWriterStartDocument buffer.
     * Host PHP 8.2 has no toMemory(); fold via new XMLWriter + openMemory.
     */
    public static function tryToMemory(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || !\extension_loaded('xmlwriter')
            || !\class_exists(\XMLWriter::class, false)
        ) {
            return null;
        }
        $writer = new \XMLWriter();
        if (!$writer->openMemory()) {
            return null;
        }

        return self::materializeFactoryObject($context, $writer);
    }

    /**
     * XMLWriter::toUri() leftover of openUri (#19606 / #35872).
     * php-src: zim_XMLWriter_toUri — static factory = new + xmlNewTextWriterFilename.
     */
    public static function tryToUri(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || !\extension_loaded('xmlwriter')
            || !\class_exists(\XMLWriter::class, false)
            || !isset($args[0])
        ) {
            return null;
        }
        $uri = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $uri || str_starts_with($uri, '__phpc_xw_')) {
            return null;
        }
        if ('' === $uri) {
            throw new \ValueError('XMLWriter::toUri(): Argument #1 ($uri) cannot be empty');
        }
        $writer = new \XMLWriter();
        if (!@$writer->openUri($uri)) {
            throw new \Error('XMLWriter::toUri(): Unable to open URI');
        }

        return self::materializeFactoryObject($context, $writer);
    }

    /**
     * XMLWriter::toStream() leftover of toMemory/toUri (#35895 / #19606).
     * Host has no toStream; fold as new XMLWriter + openUri when the stream
     * was opened from a compile-time fopen() path (php-src zim_XMLWriter_toStream).
     */
    public static function tryToStream(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()
            || !\extension_loaded('xmlwriter')
            || !\class_exists(\XMLWriter::class, false)
            || !isset($args[0])
        ) {
            return null;
        }
        $uri = self::resolveFopenPath($args[0]);
        if (null === $uri) {
            return null;
        }
        if ('' === $uri) {
            throw new \ValueError(
                'XMLWriter::toStream(): Argument #1 ($stream) is not an open stream resource'
            );
        }
        $writer = new \XMLWriter();
        if (!@$writer->openUri($uri)) {
            throw new \Error('XMLWriter::toStream(): Unable to open stream');
        }

        return self::materializeFactoryObject($context, $writer);
    }

    /** Resolve fopen literal path from stream arg token or lastFopenPath (#35895 / #35900). */
    public static function resolveFopenPath(JITVariable $stream): ?string
    {
        $token = $stream->compileTimeString;
        if (null !== $token && isset(self::$fopenPathsByToken[$token])) {
            return self::$fopenPathsByToken[$token];
        }
        // Direct path stamp (ASSIGN after fopen may copy compileTimeString = path).
        if (null !== $token && '' !== $token && !str_starts_with($token, '__phpc_')) {
            return $token;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($stream);
        if (null !== $lit && '' !== $lit && !str_starts_with($lit, '__phpc_')) {
            return $lit;
        }

        return self::$lastFopenPath;
    }

    /**
     * XMLWriter::openUri() leftover of openMemory (#35872 / #19551).
     * php-src: zim_XMLWriter_openUri / xmlTextWriterStartDocument (after open)
     */
    public static function tryOpenUri(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $uri = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $uri || str_starts_with($uri, '__phpc_xw_')) {
            return null;
        }
        $ok = @$writer->openUri($uri);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartDocument(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $version = '1.0';
        if (isset($args[1])) {
            if (JITVariable::TYPE_NULL === $args[1]->type) {
                $version = '1.0';
            } else {
                $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
                if (null === $lit || str_starts_with($lit, '__phpc_xw_')) {
                    return null;
                }
                $version = $lit;
            }
        }
        $encoding = null;
        if (isset($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $encoding = null;
            } else {
                $encoding = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
                if (null === $encoding || str_starts_with($encoding, '__phpc_xw_')) {
                    return null;
                }
            }
        }
        $ok = null === $encoding
            ? $writer->startDocument($version)
            : $writer->startDocument($version, $encoding);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->startElement($name);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteAttribute(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $value = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')
            || null === $value || str_starts_with($value, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = $writer->writeAttribute($name, $value);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartElementNS(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2], $args[3])) {
            return null;
        }
        $prefix = self::nullableCompileTimeString($args[1]);
        $name = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $uri = self::nullableCompileTimeString($args[3]);
        if (false === $prefix || false === $uri
            || null === $name || str_starts_with($name, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = $writer->startElementNS($prefix, $name, $uri);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartAttributeNS(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2], $args[3])) {
            return null;
        }
        $prefix = self::nullableCompileTimeString($args[1]);
        $name = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $uri = self::nullableCompileTimeString($args[3]);
        if (false === $prefix || false === $uri
            || null === $name || str_starts_with($name, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = $writer->startAttributeNS($prefix, $name, $uri);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteAttributeNS(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2], $args[3], $args[4])) {
            return null;
        }
        $prefix = self::nullableCompileTimeString($args[1]);
        $name = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $uri = self::nullableCompileTimeString($args[3]);
        $content = JitStringBuiltinArg::compileTimeLiteral($args[4]) ?? $args[4]->compileTimeString;
        if (false === $prefix || false === $uri
            || null === $name || str_starts_with($name, '__phpc_xw_')
            || null === $content || str_starts_with($content, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = $writer->writeAttributeNS($prefix, $name, $uri, $content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteElementNS(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2], $args[3])) {
            return null;
        }
        $prefix = self::nullableCompileTimeString($args[1]);
        $name = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $uri = self::nullableCompileTimeString($args[3]);
        if (false === $prefix || false === $uri
            || null === $name || str_starts_with($name, '__phpc_xw_')
        ) {
            return null;
        }
        if (isset($args[4])) {
            if (JITVariable::TYPE_NULL === $args[4]->type) {
                $ok = $writer->writeElementNS($prefix, $name, $uri, null);
            } else {
                $content = JitStringBuiltinArg::compileTimeLiteral($args[4]) ?? $args[4]->compileTimeString;
                if (null === $content || str_starts_with($content, '__phpc_xw_')) {
                    return null;
                }
                $ok = $writer->writeElementNS($prefix, $name, $uri, $content);
            }
        } else {
            $ok = $writer->writeElementNS($prefix, $name, $uri);
        }

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWritePI(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $target = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $content = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $target || str_starts_with($target, '__phpc_xw_')
            || null === $content || str_starts_with($content, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = @$writer->writePi($target, $content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteRaw(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->writeRaw($content);

        return self::boolValue($context, (bool) $ok);
    }

    /**
     * @return string|null|false null = PHP null; false = dynamic / cannot fold
     */
    private static function nullableCompileTimeString(JITVariable $arg): string|null|false
    {
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
        if (null === $lit || str_starts_with($lit, '__phpc_xw_')) {
            return false;
        }

        return $lit;
    }

    public static function tryStartAttribute(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->startAttribute($name);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndAttribute(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endAttribute();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryText(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->text($content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartCData(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->startCData();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndCData(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endCData();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartComment(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->startComment();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndComment(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endComment();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartDtd(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $publicId = null;
        $systemId = null;
        if (isset($args[2])) {
            $publicId = self::nullableCompileTimeString($args[2]);
            if (false === $publicId) {
                return null;
            }
        }
        if (isset($args[3])) {
            $systemId = self::nullableCompileTimeString($args[3]);
            if (false === $systemId) {
                return null;
            }
        }
        $ok = @$writer->startDtd($name, $publicId, $systemId);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndDtd(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endDtd();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteDtd(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $publicId = null;
        $systemId = null;
        $content = null;
        if (isset($args[2])) {
            $publicId = self::nullableCompileTimeString($args[2]);
            if (false === $publicId) {
                return null;
            }
        }
        if (isset($args[3])) {
            $systemId = self::nullableCompileTimeString($args[3]);
            if (false === $systemId) {
                return null;
            }
        }
        if (isset($args[4])) {
            $content = self::nullableCompileTimeString($args[4]);
            if (false === $content) {
                return null;
            }
        }
        $ok = @$writer->writeDtd($name, $publicId, $systemId, $content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteDtdElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $content = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')
            || null === $content || str_starts_with($content, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = @$writer->writeDtdElement($name, $content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartDtdElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $ok = @$writer->startDtdElement($name);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndDtdElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endDtdElement();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteDtdAttlist(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $content = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')
            || null === $content || str_starts_with($content, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = @$writer->writeDtdAttlist($name, $content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartDtdAttlist(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $ok = @$writer->startDtdAttlist($name);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndDtdAttlist(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endDtdAttlist();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartDtdEntity(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $isParam = self::compileTimeEmptyFlag($args[2]);
        if (null === $isParam) {
            return null;
        }
        $ok = @$writer->startDtdEntity($name, $isParam);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndDtdEntity(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endDtdEntity();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteDtdEntity(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $content = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')
            || null === $content || str_starts_with($content, '__phpc_xw_')
        ) {
            return null;
        }
        $isParam = false;
        $publicId = null;
        $systemId = null;
        $notationData = null;
        if (isset($args[3])) {
            $isParamBool = self::compileTimeEmptyFlag($args[3]);
            if (null === $isParamBool) {
                return null;
            }
            $isParam = $isParamBool;
        }
        if (isset($args[4])) {
            $publicId = self::nullableCompileTimeString($args[4]);
            if (false === $publicId) {
                return null;
            }
        }
        if (isset($args[5])) {
            $systemId = self::nullableCompileTimeString($args[5]);
            if (false === $systemId) {
                return null;
            }
        }
        if (isset($args[6])) {
            $notationData = self::nullableCompileTimeString($args[6]);
            if (false === $notationData) {
                return null;
            }
        }
        $ok = @$writer->writeDtdEntity($name, $content, $isParam, $publicId, $systemId, $notationData);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartPI(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $target = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $target || str_starts_with($target, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->startPI($target);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndPI(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endPI();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryFullEndElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->fullEndElement();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endElement();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndDocument(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endDocument();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryOutputMemory(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $flush = self::compileTimeEmptyFlag($args[1] ?? null);
        if (null === $flush) {
            return null;
        }
        $out = $writer->outputMemory($flush);

        return self::stringValue($context, (string) $out);
    }

    public static function tryFlush(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $empty = self::compileTimeEmptyFlag($args[1] ?? null);
        if (null === $empty) {
            return null;
        }
        $out = $writer->flush($empty);
        if (\is_string($out)) {
            return self::stringValue($context, $out);
        }

        return self::intValue($context, (int) $out);
    }

    /**
     * Procedural xmlwriter_* folding for user-script AOT (#19514).
     *
     * open_memory / open_uri create a host writer tracked via lastWriter so
     * subsequent procedural calls (writer as arg0) fold like OOP methods.
     */
    public static function tryProcedural(Context $context, string $function, JITVariable ...$args): ?Value
    {
        if (!\extension_loaded('xmlwriter') || !\class_exists(\XMLWriter::class, false)) {
            return null;
        }
        $fn = strtolower($function);

        return match ($fn) {
            'xmlwriter_open_memory' => self::tryProceduralOpenMemory($context),
            'xmlwriter_open_uri' => self::tryProceduralOpenUri($context, ...$args),
            'xmlwriter_set_indent' => self::trySetIndent($context, ...$args),
            'xmlwriter_set_indent_string' => self::trySetIndentString($context, ...$args),
            'xmlwriter_start_document' => self::tryStartDocument($context, ...$args),
            'xmlwriter_end_document' => self::tryEndDocument($context, ...$args),
            'xmlwriter_start_element' => self::tryStartElement($context, ...$args),
            'xmlwriter_start_element_ns' => self::tryStartElementNS($context, ...$args),
            'xmlwriter_end_element' => self::tryEndElement($context, ...$args),
            'xmlwriter_full_end_element' => self::tryFullEndElement($context, ...$args),
            'xmlwriter_write_attribute' => self::tryWriteAttribute($context, ...$args),
            'xmlwriter_write_attribute_ns' => self::tryWriteAttributeNS($context, ...$args),
            'xmlwriter_start_attribute' => self::tryStartAttribute($context, ...$args),
            'xmlwriter_start_attribute_ns' => self::tryStartAttributeNS($context, ...$args),
            'xmlwriter_end_attribute' => self::tryEndAttribute($context, ...$args),
            'xmlwriter_write_element' => self::tryWriteElement($context, ...$args),
            'xmlwriter_write_element_ns' => self::tryWriteElementNS($context, ...$args),
            'xmlwriter_write_cdata' => self::tryWriteCData($context, ...$args),
            'xmlwriter_start_cdata' => self::tryStartCData($context, ...$args),
            'xmlwriter_end_cdata' => self::tryEndCData($context, ...$args),
            'xmlwriter_write_comment' => self::tryWriteComment($context, ...$args),
            'xmlwriter_start_comment' => self::tryStartComment($context, ...$args),
            'xmlwriter_end_comment' => self::tryEndComment($context, ...$args),
            'xmlwriter_write_raw' => self::tryWriteRaw($context, ...$args),
            'xmlwriter_write_pi' => self::tryWritePI($context, ...$args),
            'xmlwriter_start_pi' => self::tryStartPI($context, ...$args),
            'xmlwriter_end_pi' => self::tryEndPI($context, ...$args),
            'xmlwriter_write_dtd' => self::tryWriteDtd($context, ...$args),
            'xmlwriter_start_dtd' => self::tryStartDtd($context, ...$args),
            'xmlwriter_end_dtd' => self::tryEndDtd($context, ...$args),
            'xmlwriter_write_dtd_element' => self::tryWriteDtdElement($context, ...$args),
            'xmlwriter_write_dtd_attlist' => self::tryWriteDtdAttlist($context, ...$args),
            'xmlwriter_start_dtd_entity' => self::tryStartDtdEntity($context, ...$args),
            'xmlwriter_end_dtd_entity' => self::tryEndDtdEntity($context, ...$args),
            'xmlwriter_write_dtd_entity' => self::tryWriteDtdEntity($context, ...$args),
            'xmlwriter_start_dtd_attlist' => self::tryStartDtdAttlist($context, ...$args),
            'xmlwriter_end_dtd_attlist' => self::tryEndDtdAttlist($context, ...$args),
            'xmlwriter_start_dtd_element' => self::tryStartDtdElement($context, ...$args),
            'xmlwriter_end_dtd_element' => self::tryEndDtdElement($context, ...$args),
            'xmlwriter_text' => self::tryText($context, ...$args),
            'xmlwriter_output_memory' => self::tryOutputMemory($context, ...$args),
            'xmlwriter_flush' => self::tryFlush($context, ...$args),
            default => null,
        };
    }

    private static function tryProceduralOpenMemory(Context $context): ?Value
    {
        $writer = new \XMLWriter();
        $ok = $writer->openMemory();
        if (!$ok) {
            return null;
        }
        // No destination JITVariable here — track via lastWriter for subsequent calls.
        self::$lastWriter = $writer;

        return self::nullValue($context);
    }

    private static function tryProceduralOpenUri(Context $context, JITVariable ...$args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        $uri = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $uri || str_starts_with($uri, '__phpc_xw_')) {
            return null;
        }
        $writer = new \XMLWriter();
        $ok = @$writer->openUri($uri);
        if (!$ok) {
            return self::boolValue($context, false);
        }
        self::$lastWriter = $writer;

        return self::nullValue($context);
    }

    /** XMLWriter::setIndent() / xmlwriter_set_indent() — leftover of writeElementNS (#35865). */
    public static function trySetIndent(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $enable = self::compileTimeEmptyFlag($args[1]);
        if (null === $enable) {
            return null;
        }
        $ok = $writer->setIndent($enable);

        return self::boolValue($context, (bool) $ok);
    }

    /** XMLWriter::setIndentString() / xmlwriter_set_indent_string() — leftover of writeElementNS (#35865). */
    public static function trySetIndentString(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $indentation = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $indentation || str_starts_with($indentation, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->setIndentString($indentation);

        return self::boolValue($context, (bool) $ok);
    }

    /**
     * XMLWriter::writeElement() / xmlwriter_write_element() leftover of writeElementNS (#35865).
     * php-src: zim_XMLWriter_writeElement / xmlTextWriterWriteElement
     */
    public static function tryWriteElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $content = null;
        if (isset($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $content = null;
            } else {
                $content = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
                if (null === $content || str_starts_with($content, '__phpc_xw_')) {
                    return null;
                }
            }
            $ok = $writer->writeElement($name, $content);
        } else {
            $ok = $writer->writeElement($name);
        }

        return self::boolValue($context, (bool) $ok);
    }

    /** XMLWriter::writeCdata() / xmlwriter_write_cdata() leftover of writeElementNS (#35865). */
    public static function tryWriteCData(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->writeCData($content);

        return self::boolValue($context, (bool) $ok);
    }

    /** XMLWriter::writeComment() / xmlwriter_write_comment() leftover of writeElementNS (#35865). */
    public static function tryWriteComment(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->writeComment($content);

        return self::boolValue($context, (bool) $ok);
    }

    /** @return ?bool null = dynamic / unknown (cannot fold) */
    private static function compileTimeEmptyFlag(?JITVariable $arg): ?bool
    {
        if (null === $arg) {
            return true;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== (int) $arg->compileTimeLong;
        }
        // CONST_FETCH true/false sets compileTimeConstantName even when the LLVM load
        // is Instruction-backed (#26774).
        if (null !== $arg->compileTimeConstantName) {
            $cn = strtolower($arg->compileTimeConstantName);
            if ('true' === $cn) {
                return true;
            }
            if ('false' === $cn) {
                return false;
            }
        }
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return true;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            // Constant int1: CallUnpackCompileTime uses constInt(false). Instruction-backed
            // bools (common for `true`/`false` temporaries) cannot be folded here.
            if (\is_object($const) && \method_exists($const, 'constInt')) {
                try {
                    return 0 !== (int) $const->constInt(false);
                } catch (\Throwable $e) {
                    return null;
                }
            }
            if (\is_object($const) && \method_exists($const, 'isConstant') && $const->isConstant()
                && \method_exists($const, 'getConstantValue')) {
                return 0 !== (int) $const->getConstantValue();
            }
        }

        return null;
    }

    /**
     * Allocate a constructed XMLWriter box and attach the host writer (#19606).
     */
    private static function materializeFactoryObject(Context $context, \XMLWriter $writer): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'xmlwriter_factory_cont');
        $objectType = $context->type->object;
        $classId = $objectType->lookup('XMLWriter');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $receiver = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        self::store($receiver, $writer);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function requireWriter(?JITVariable $receiver): ?\XMLWriter
    {
        if (null === $receiver) {
            return null;
        }
        $writer = self::lookup($receiver);
        if (null !== $writer) {
            return $writer;
        }
        if (!\extension_loaded('xmlwriter') || !\class_exists(\XMLWriter::class, false)) {
            return null;
        }
        // Lazy init when NEW did not run through tryInit (no-arg constructed object).
        $writer = new \XMLWriter();
        self::store($receiver, $writer);

        return $writer;
    }

    private static function store(JITVariable $receiver, \XMLWriter $writer): void
    {
        if (null === self::$writers) {
            self::$writers = new \SplObjectStorage();
        }
        self::$writers[$receiver] = $writer;
        $token = '__phpc_xw_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$writersByToken[$token] = $writer;
        self::$lastWriter = $writer;
    }

    /** Stamp EXEC_RETURN / `$w = XMLWriter::toMemory()` so later methods find the host writer. */
    public static function bindResultVariable(JITVariable $var): void
    {
        if (null === self::$lastWriter) {
            return;
        }
        self::store($var, self::$lastWriter);
    }

    private static function lookup(JITVariable $receiver): ?\XMLWriter
    {
        if (null !== self::$writers && isset(self::$writers[$receiver])) {
            return self::$writers[$receiver];
        }
        $token = $receiver->compileTimeString;
        if (null !== $token && isset(self::$writersByToken[$token])) {
            return self::$writersByToken[$token];
        }

        return self::$lastWriter;
    }

    private static function boolValue(Context $context, bool $ok): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt($ok ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function intValue(Context $context, int $n): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt($n, true));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function stringValue(Context $context, string $xml): Value
    {
        $str = $context->builder->load($context->constantStringFromString($xml));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function nullValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}

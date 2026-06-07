<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_mime_content_type (ext/standard/file.c; #6196).
 *
 * Reads path bytes via __compiler_file_get_contents and mirrors {@see \PHPCompiler\ext\standard\VmMime::detectFromBytes()}.
 */
final class MimeContentTypeRuntime
{
    private const MIME_PHP = 'text/x-php';

    private const MIME_OCTET = 'application/octet-stream';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_mime_content_type');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_mime_content_type', $ft);
        self::implementMimeContentType($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementMimeContentType(Context $context, Value $fn): void
    {
        self::ensureLibc($context);
        StringFileGetContents::implement($context);

        $entry = $fn->appendBasicBlock('mime_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        $data = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $path
        );
        $missing = $context->builder->icmp(Builder::INT_EQ, $data, $nullStr);
        $failBlock = $fn->appendBasicBlock('mime_missing');
        $okBlock = $fn->appendBasicBlock('mime_sniff');
        $context->builder->branchIf($missing, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBlock);
        self::detectFromString($context, $fn, $data);

        $context->builder->clearInsertionPosition();
    }

    private static function detectFromString(Context $context, Value $fn, Value $data): void
    {
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);

        $len = $context->builder->load(
            $context->builder->structGep($data, $strMap['length'])
        );
        $bytes = $context->builder->structGep($data, $strMap['value']);

        $phpMime = self::literalString($context, self::MIME_PHP);
        $octetMime = self::literalString($context, self::MIME_OCTET);

        $minPhp = $i64->constInt(5, false);
        $hasPhp = $context->builder->icmp(Builder::INT_SGE, $len, $minPhp);
        $phpBlock = $fn->appendBasicBlock('mime_check_php');
        $octetBlock = $fn->appendBasicBlock('mime_octet');
        $context->builder->branchIf($hasPhp, $phpBlock, $octetBlock);

        $context->builder->positionAtEnd($phpBlock);
        $phpLit = self::allocLiteral($context, '<?php', 5);
        $phpMatch = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $bytes,
            $phpLit,
            $sizeT->constInt(5, false)
        );
        $isPhp = $context->builder->icmp(Builder::INT_EQ, $phpMatch, $zeroI32);
        $phpOkBlock = $fn->appendBasicBlock('mime_php_ok');
        $context->builder->branchIf($isPhp, $phpOkBlock, $octetBlock);

        $context->builder->positionAtEnd($phpOkBlock);
        $context->builder->returnValue($phpMime);

        $context->builder->positionAtEnd($octetBlock);
        $context->builder->returnValue($octetMime);
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $len = \strlen($text);
        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $i64->constInt($len, false)
        );
        $ptr = $context->builder->pointerCast($buf, $i8p);
        for ($i = 0; $i < $len; ++$i) {
            $context->builder->store(
                $i8->constInt(\ord($text[$i]), false),
                $context->builder->inBoundsGEP($ptr, $i64->constInt($i, false))
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt($len, false),
            $ptr
        );
    }

    private static function allocLiteral(Context $context, string $text, int $len): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $i64->constInt($len + 1, false)
        );
        $ptr = $context->builder->pointerCast($buf, $i8p);
        for ($i = 0; $i < $len; ++$i) {
            $context->builder->store(
                $i8->constInt(\ord($text[$i]), false),
                $context->builder->inBoundsGEP($ptr, $i64->constInt($i, false))
            );
        }
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($ptr, $i64->constInt($len, false))
        );

        return $ptr;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ftStrncmp = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
        if (null === $context->module->getNamedFunction('strncmp')) {
            $context->module->addFunction('strncmp', $ftStrncmp);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $context->module->getNamedFunction('__compiler_mime_content_type');
        if (null !== $fn) {
            $context->registerFunction('__compiler_mime_content_type', $fn);
        }
    }
}

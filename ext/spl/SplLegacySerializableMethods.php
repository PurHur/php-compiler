<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\ext\standard\VmSerializeRefState;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCfg\Func as CfgFunc;

/**
 * Legacy Serializable::serialize()/unserialize() on SPL builtins (php-src ext/spl; #14756).
 */
final class SplLegacySerializableMethods
{
    public static function register(ClassEntry $entry, string $ownerLc, string $displayName): void
    {
        if (isset($entry->methods['serialize'], $entry->methods['unserialize'])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->methods['serialize'] = new SplLegacySerializableSerialize($ownerLc, $displayName);
        $entry->methodVisibility['serialize'] = $pub;
        $entry->methods['unserialize'] = new SplLegacySerializableUnserialize($ownerLc, $displayName);
        $entry->methodVisibility['unserialize'] = $pub;
        // php-src stub arginfo — required for subclass LSP vs Serializable (#25840, #25406).
        $entry->methodParameterMetadata['serialize'] = [];
        $entry->methodParameterMetadata['unserialize'] = [
            new ParameterMetadata('data', [], false, false, false, false, 'string', null),
        ];
        $entry->methodNames['serialize'] = 'serialize';
        $entry->methodNames['unserialize'] = 'unserialize';
    }
}

final class SplLegacySerializableSerialize extends VmClassMethod
{
    public function __construct(
        private readonly string $ownerLc,
        private readonly string $displayName
    ) {
        parent::__construct('serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            $this->ownerLc,
            $this->displayName.'::serialize()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911)
        $this->requireExactUserArgCount($frame, $this->displayName.'::serialize', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(self::encodePayload($frame->vmContext, $object));
    }

    private static function encodePayload(Context $ctx, ObjectEntry $object): string
    {
        $lcClass = strtolower($object->class->name);
        if (SplArraySerializeSupport::isSplArrayClass($lcClass)) {
            return SplArraySerializeSupport::encodeZendSerializeWire($object);
        }
        if (SplDllistSerializeSupport::isSplDllistClass($lcClass)) {
            return SplDllistSerializeSupport::encodeZendSerializeWire($object);
        }
        if (SplObjectStorageSerializeSupport::isSplObjectStorageClass($lcClass)) {
            return SplObjectStorageSerializeSupport::encodeZendSerializeWire(
                $ctx,
                $object,
                new VmSerializeRefState(),
                null
            );
        }

        return '';
    }
}

final class SplLegacySerializableUnserialize extends VmClassMethod
{
    public function __construct(
        private readonly string $ownerLc,
        private readonly string $displayName
    ) {
        parent::__construct('unserialize');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            $this->ownerLc,
            $this->displayName.'::unserialize()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                $this->displayName.'::unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        // Zend mutates $this in place; global serialize()/unserialize() use dedicated SPL wire paths (#14164).
    }
}

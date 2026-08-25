<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * serialize() — VmSerialize SSOT; JIT/AOT via SerializeJitHelper (#9180).
 */
final class serialize extends Internal
{
    public function __construct()
    {
        parent::__construct('serialize');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/var.c — ArgumentCountError (#28474).
        $this->requireExactArgCount($frame, 'serialize', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $encoded = VmSerialize::serializeValue(
            $frame->vmContext,
            $frame->calledArgs[0],
            $frame
        );
        $frame->returnVar->string($encoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'serialize', 1)) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        $compileTime = self::compileTimeSerialize($context, $args[0]);
        if (null !== $compileTime) {
            $context->jitSerializeFoldedString = $compileTime;

            return $context->builder->load($context->constantStringFromString($compileTime));
        }

        return JitSerialize::encode($context, $args[0]);
    }

    private static function compileTimeSerialize(Context $context, JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return 'N;';
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return 0 === (int) $context->llvm->lib->LLVMConstIntGetZExtValue($arg->value->value)
                ? 'b:0;'
                : 'b:1;';
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $n = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($arg->value->value);

            return 'i:'.$n.';';
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            $literal = JitStringArg::compileTimeLiteral($arg);
            if (null !== $literal) {
                return VmSerializeFormat::encodeStringLiteral($literal);
            }
        }
        // DateTime* — Zend date/timezone wire (#34576 / re-#10710). Peer json_encode #33752.
        if (null !== $arg->compileTimeDateTimeTimestamp) {
            $className = $arg->compileTimeDateTimeClassName;
            if (null === $className || '' === $className) {
                $className = 'DateTime';
            }
            if ('DateTime' !== $className && 'DateTimeImmutable' !== $className) {
                return null;
            }
            $tz = $arg->compileTimeTimezoneName ?? 'UTC';
            $micro = (int) ($arg->compileTimeDateTimeMicrosecond ?? 0);
            $props = [
                'date' => VmDateTimeNative::formatZendDateWire(
                    (int) $arg->compileTimeDateTimeTimestamp,
                    $micro,
                    $tz
                ),
                'timezone_type' => \PHPCompiler\VM\DateTimeSupport::zendTimezoneWireType($tz),
                'timezone' => $tz,
            ];

            return VmSerialize::encodeExportedPropertyBag($className, $props);
        }

        // DateInterval — Zend member wire (#34584 / re-#10692). Peer DateTime #34576.
        if (\is_array($arg->compileTimeDateInterval)) {
            return self::encodeDateIntervalStamp($arg->compileTimeDateInterval);
        }

        // DateTimeZone — zone id stamp; compileTimeString holds id not class name (#29732 / #34584).
        if (
            null !== $arg->compileTimeTimezoneName
            && null === $arg->compileTimeDateTimeTimestamp
            && 'DateTimeZone' === ($arg->classUserType ?? '')
        ) {
            $tz = $arg->compileTimeTimezoneName;

            return VmSerialize::encodeExportedPropertyBag('DateTimeZone', [
                'timezone_type' => \PHPCompiler\VM\DateTimeSupport::zendTimezoneWireType($tz),
                'timezone' => $tz,
            ]);
        }

        return null;
    }

    /**
     * Zend DateInterval serialize wire from a construct / createFromDateString stamp (#34584).
     *
     * @param array<string, mixed> $state
     */
    public static function encodeDateIntervalStamp(array $state): string
    {
        if (
            !empty($state['from_string'])
            && isset($state['date_string'])
            && \is_string($state['date_string'])
        ) {
            return VmSerialize::encodeExportedPropertyBag('DateInterval', [
                'from_string' => true,
                'date_string' => $state['date_string'],
            ]);
        }

        return VmSerialize::encodeExportedPropertyBag('DateInterval', [
            'y' => (int) ($state['y'] ?? 0),
            'm' => (int) ($state['m'] ?? 0),
            'd' => (int) ($state['d'] ?? 0),
            'h' => (int) ($state['h'] ?? 0),
            'i' => (int) ($state['i'] ?? 0),
            's' => (int) ($state['s'] ?? 0),
            'f' => (float) ($state['f'] ?? 0.0),
            'invert' => (int) ($state['invert'] ?? 0),
            'days' => \array_key_exists('days', $state) ? $state['days'] : false,
            'from_string' => false,
        ]);
    }
}

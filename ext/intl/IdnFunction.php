<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for idn_to_ascii()/idn_to_utf8() (php-src ext/intl/idn/idn.c; #6169).
 */
abstract class IdnFunction extends Internal
{
    abstract protected function convertMode(): bool;

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least 1 argument, %d given',
                $this->getName(),
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 4 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $domain = VmString::requireStringBuiltinArg(
            $frame->calledArgs[0],
            $this->getName(),
            0,
            'domain'
        );
        $flags = VmIdn::IDNA_DEFAULT;
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                $this->getName(),
                1,
                'flags'
            );
        }
        $variant = VmIdn::VARIANT_UTS46;
        if ($argc >= 3) {
            $variant = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                $this->getName(),
                2,
                'variant'
            );
        }

        $info = null;
        $wantInfo = $argc >= 4;
        $result = $this->convertMode()
            ? VmIdn::toAscii($domain, $flags, $variant, $info)
            : VmIdn::toUtf8($domain, $flags, $variant, $info);

        if ($wantInfo && null !== $info) {
            VmIdn::writeIdnaInfo($frame, 3, $info);
        }

        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->getName().'() JIT runtime lowering is deferred; use VM (#6169)'
        );
    }
}

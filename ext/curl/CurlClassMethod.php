<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;

/** Shared VM wiring for ext/curl CurlHandle methods (php-src ext/curl/curl.stub.php; #21837). */
abstract class CurlClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmCurlArg::requireEasyObject($frame->calledArgs[0], $label, 1);
    }
}

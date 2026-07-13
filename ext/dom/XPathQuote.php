<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** DOMXPath::quote() — XPath literal escaping (php-src ext/dom/xpath.c; #18650). */
final class XPathQuote extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('quote');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('DOMXPath::quote() expects at least 1 argument, 0 given');
        }
        $str = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'DOMXPath::quote', 0, 'str');
        VmString::rejectNullByteBuiltinStringArg($str, 'DOMXPath::quote', 0, 'str');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDomXPath::quote($str));
    }
}

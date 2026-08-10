<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMAttr::isId() AOT helper (#29884, re-#20129).
 *
 * Returns int 0/1 — NestedJIT bool-box is unsafe in thin helper TUs (peer DomIsEqualNodeJitHelper).
 */
final class DomAttrIsIdJitHelper
{
    public static function isIdArgv(Context $ctx, ObjectEntry $attr): int
    {
        return VmDom::attrIsId($attr) ? 1 : 0;
    }
}

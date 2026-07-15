<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMXPath::query() for compiled JIT/AOT modules (#18493).
 *
 * SSOT: {@see VmDomXPath::query()}
 * php-src: ext/dom/xpath.c — dom_xpath_object_query_read
 */
final class DomXPathQueryJitHelper
{
    public static function queryStringArgv(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression
    ): ObjectEntry {
        return VmDomXPath::query($ctx, $xpath, $expression)->toObject();
    }
}

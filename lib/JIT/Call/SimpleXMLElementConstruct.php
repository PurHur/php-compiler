<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\simplexml\JitSimpleXmlConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SimpleXMLElement::__construct() — user-script AOT (#19306). */
final class SimpleXMLElementConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitSimpleXmlConstruct::invoke($context, ...$args);
    }
}

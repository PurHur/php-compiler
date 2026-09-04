<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMCharacterData::insertData() — user-script AOT (php-src xmlTextInsert) (#32380). */
final class DomCharacterDataInsertData implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'characterData.insertData',
            ...$args
        );
    }
}

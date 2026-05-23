#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = '/home/ai/php-compiler';

function patch(string $rel, callable $fn): void
{
    global $root;
    $path = $root.'/'.$rel;
    if (!is_file($path)) {
        return;
    }
    $text = (string) file_get_contents($path);
    $next = $fn($text);
    if ($text !== $next) {
        file_put_contents($path, $next);
        echo "patched {$rel}\n";
    }
}

function ensureUse(string $text, string $use): string
{
    return str_contains($text, $use)
        ? $text
        : str_replace('use PHPCompiler\\JIT\\Context;', "use PHPCompiler\\JIT\\Context;\n{$use}", $text);
}

function hasJitMarker(string $text): bool
{
    return (bool) preg_match('/JitStringArg|JitLongArg|->jitString\s*\(/', $text);
}

function insertBeforeReturn(string $text, string $insert): string
{
    return (string) preg_replace('/(\n        return )/', "\n{$insert}$1", $text, 1);
}

function insertAfterArgCheck(string $text, string $insert): string
{
    return (string) preg_replace(
        '/(if \(1 !== \\\\?count\(\$args\)\) \{[\s\S]*?\}\n)/',
        "$1{$insert}",
        $text,
        1
    );
}

$arrayInsert = <<<'PHP'
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'FN() argument #'.((int) $i + 1));
            }
        }

PHP;

foreach ([
    'array_combine', 'array_count', 'array_filter', 'array_flip', 'array_keys', 'array_merge',
    'array_pop', 'array_product', 'array_push', 'array_reverse', 'array_shift', 'array_sum',
    'array_unique', 'array_values',
] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn, $arrayInsert): string {
        if (hasJitMarker($t) || !preg_match('/public function call\(/', $t)) {
            return $t;
        }
        $insert = str_replace('FN', $fn, $arrayInsert);
        if (in_array($fn, ['array_count', 'array_product', 'array_sum'], true)) {
            return insertAfterArgCheck($t, $insert);
        }

        return insertBeforeReturn($t, rtrim($insert));
    });
}

patch('ext/standard/sort_.php', static fn (string $t): string => hasJitMarker($t) ? $t : insertBeforeReturn($t, rtrim(str_replace('FN', 'sort', $arrayInsert))));

patch('ext/standard/compact_.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }

    return insertBeforeReturn($t, <<<'PHP'
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'compact() variable name #'.((int) $i + 1));
            }
        }
PHP);
});

patch('ext/standard/extract_.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return str_replace(
        '$flags = 2 === \count($args) ? $args[1] : null;

        return ScopeBuiltinHelper::extract($context, $args[0], $flags);',
        '$flags = 2 === \count($args) ? $args[1] : null;
        if (null !== $flags) {
            JitLongArg::lower($context, $flags, \'extract() flags\');
        }

        return ScopeBuiltinHelper::extract($context, $args[0], $flags);',
        $t
    );
});

patch('ext/standard/defined_.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }

    return str_replace(
        'if (JITVariable::TYPE_STRING !== $args[0]->type || null === $args[0]->compileTimeString) {',
        'if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], \'defined() constant name\');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === $args[0]->compileTimeString) {',
        $t
    );
});

patch('ext/standard/define_.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }

    return str_replace(
        'throw new \LogicException(
            \'define() is not implemented for JIT; use literal name and value (folded at compile time)\'
        );',
        'if (\count($args) >= 1 && (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type)) {
            $this->jitString($context, $args[0], \'define() constant name\');
        }
        throw new \LogicException(
            \'define() is not implemented for JIT; use literal name and value (folded at compile time)\'
        );',
        $t
    );
});

foreach (['fopen' => "        if (2 !== \\count(\$args)) {\n            throw new \\LogicException('fopen() requires exactly two arguments in this compiler build');\n        }\n        \$this->jitString(\$context, \$args[0], 'fopen() path');\n        \$this->jitString(\$context, \$args[1], 'fopen() mode');\n        throw new \\LogicException('fopen() is not implemented for JIT in this compiler build');",
    'fclose' => "        if (1 !== \\count(\$args)) {\n            throw new \\LogicException('fclose() requires exactly one argument in this compiler build');\n        }\n        JitLongArg::lower(\$context, \$args[0], 'fclose() handle');\n        throw new \\LogicException('fclose() is not implemented for JIT in this compiler build');",
    'fread' => "        if (2 !== \\count(\$args)) {\n            throw new \\LogicException('fread() requires exactly two arguments in this compiler build');\n        }\n        JitLongArg::lower(\$context, \$args[0], 'fread() handle');\n        JitLongArg::lower(\$context, \$args[1], 'fread() length');\n        throw new \\LogicException('fread() is not implemented for JIT in this compiler build');",
] as $fn => $body) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($body, $fn): string {
        if (hasJitMarker($t)) {
            return $t;
        }
        if ('fclose' === $fn || 'fread' === $fn) {
            $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
        }

        return (string) preg_replace(
            '/public function call\(Context \$context, JITVariable \.\.\.\$args\): Value\n    \{[\s\S]*?\n    \}/',
            "public function call(Context \$context, JITVariable ...\$args): Value\n    {\n{$body}\n    }",
            $t,
            1
        );
    });
}

patch('ext/standard/fwrite.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return (string) preg_replace(
        '/public function call\(Context \$context, JITVariable \.\.\.\$args\): Value\n    \{[\s\S]*?\n    \}/',
        'public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException(\'fwrite() requires two or three arguments in this compiler build\');
        }
        JitLongArg::lower($context, $args[0], \'fwrite() handle\');
        $this->jitString($context, $args[1], \'fwrite() data\');
        if (3 === $argc) {
            JitLongArg::lower($context, $args[2], \'fwrite() length\');
        }
        throw new \LogicException(\'fwrite() is not implemented for JIT in this compiler build\');
    }',
        $t,
        1
    );
});

patch('ext/standard/getenv_.php', static fn (string $t): string => hasJitMarker($t) ? $t : str_replace(
    'return JitEnv::getenv($context, $context->helper->loadValue($args[0]));',
    "return JitEnv::getenv(\$context, \$this->jitString(\$context, \$args[0], 'getenv() name'));",
    $t
));

patch('ext/standard/header_.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
    $t = str_replace('$line = $context->helper->loadValue($args[0]);', '$line = $this->jitString($context, $args[0], \'header() line\');', $t);
    $t = str_replace('HttpResponseCode::emitStandaloneStatusLine($context, $context->helper->loadValue($args[2]));', 'HttpResponseCode::emitStandaloneStatusLine($context, JitLongArg::lower($context, $args[2], \'header() response_code\'));', $t);
    $t = str_replace('$replaceI32 = $context->builder->zExt($context->helper->loadValue($args[1]), $i32);', '$replaceI32 = $context->builder->zExt($this->jitBool($context, $args[1], \'header() replace\'), $i32);', $t);

    return $t;
});

patch('ext/standard/header_remove.php', static fn (string $t): string => hasJitMarker($t) ? $t : str_replace(
    'JitPendingHeaders::remove($context, $args[0]);',
    "\$this->jitString(\$context, \$args[0], 'header_remove() name');\n            JitPendingHeaders::remove(\$context, \$args[0]);",
    $t
));

patch('ext/standard/http_response_code.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return str_replace(
        'return JitHttpResponseCode::invoke($context, ...$args);',
        "if (1 === \\count(\$args)) {\n            JitLongArg::lower(\$context, \$args[0], 'http_response_code() code');\n        }\n\n        return JitHttpResponseCode::invoke(\$context, ...\$args);",
        $t
    );
});

patch('ext/standard/doubleval.php', static fn (string $t): string => hasJitMarker($t) ? $t : str_replace(
    'return $this->delegate->call($context, ...$args);',
    "if (1 === \\count(\$args) && (JITVariable::TYPE_STRING === \$args[0]->type || JITVariable::TYPE_VALUE === \$args[0]->type)) {\n            \$this->jitString(\$context, \$args[0], 'doubleval() argument #1');\n        }\n\n        return \$this->delegate->call(\$context, ...\$args);",
    $t
));

patch('ext/standard/pow.php', static function (string $t): string {
    if (str_contains($t, 'JitLongArg::lower')) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return str_replace('$v = $context->helper->loadValue($arg);', '$v = JitLongArg::lower($context, $arg, \'pow() argument\');', $t);
});

foreach (['exp', 'fmod'] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn): string {
        if (hasJitMarker($t)) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

        return insertBeforeReturn($t, "        if (JITVariable::TYPE_NATIVE_LONG === \$args[0]->type) {\n            JitLongArg::lower(\$context, \$args[0], '{$fn}() argument #1');\n        }\n        if (isset(\$args[1]) && JITVariable::TYPE_NATIVE_LONG === \$args[1]->type) {\n            JitLongArg::lower(\$context, \$args[1], '{$fn}() argument #2');\n        }\n");
    });
}

foreach (['int_max' => 'max', 'int_min' => 'min'] as $file => $fn) {
    patch("ext/standard/{$file}.php", static function (string $t) use ($fn): string {
        if (str_contains($t, 'JitLongArg::lower')) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

        return str_replace(
            '$l = $context->helper->loadValue($args[0]);'."\n            ".'$r = $context->helper->loadValue($args[1]);',
            "\$l = JitLongArg::lower(\$context, \$args[0], '{$fn}() argument #1');\n            \$r = JitLongArg::lower(\$context, \$args[1], '{$fn}() argument #2');",
            $t
        );
    });
}

patch('ext/standard/range.php', static function (string $t): string {
    if (str_contains($t, 'JitLongArg::lower')) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
    $t = str_replace('$start = $context->helper->loadValue($args[0]);', '$start = JitLongArg::lower($context, $args[0], \'range() start\');', $t);
    $t = str_replace('$end = $context->helper->loadValue($args[1]);', '$end = JitLongArg::lower($context, $args[1], \'range() end\');', $t);
    $t = str_replace('$step = $context->helper->loadValue($args[2]);', '$step = JitLongArg::lower($context, $args[2], \'range() step\');', $t);

    return $t;
});

foreach (['is_finite', 'is_infinite', 'is_nan'] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn): string {
        if (str_contains($t, 'JitLongArg::lower')) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

        return str_replace('$asFloat = $context->helper->loadValue($args[0]);', "\$asFloat = JitLongArg::lower(\$context, \$args[0], '{$fn}() argument #1');", $t);
    });
}

foreach (['is_scalar' => 'is_scalar() argument #1', 'gettype' => 'gettype() argument #1'] as $fn => $label) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($label): string {
        if (hasJitMarker($t)) {
            return $t;
        }

        return str_replace(
            'switch ($args[0]->type) {',
            "if (JITVariable::TYPE_STRING === \$args[0]->type) {\n            \$this->jitString(\$context, \$args[0], '{$label}');\n        }\n        switch (\$args[0]->type) {",
            $t
        );
    });
}

patch('ext/standard/boolval.php', static function (string $t): string {
    if (hasJitMarker($t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
    $t = str_replace("case JITVariable::TYPE_NATIVE_LONG:\n                \$v = \$context->helper->loadValue(\$args[0]);", "case JITVariable::TYPE_NATIVE_LONG:\n                \$v = JitLongArg::lower(\$context, \$args[0], 'boolval() argument #1');", $t);
    $t = str_replace('return self::stringTruthy($context, $context->helper->loadValue($args[0]));', "return self::stringTruthy(\$context, \$this->jitString(\$context, \$args[0], 'boolval() argument #1'));", $t);

    return $t;
});

patch('lib/JIT/SelfHostBuiltinPolicy.php', static function (string $t): string {
    if (str_contains($t, "'fopen' => 'filesystem'")) {
        return $t;
    }
    $t = str_replace(
        "'is_writable' => 'filesystem', 'file_get_contents' => 'filesystem', 'realpath' => 'filesystem',\n    ];",
        "'is_writable' => 'filesystem', 'file_get_contents' => 'filesystem', 'realpath' => 'filesystem',\n        'fopen' => 'filesystem', 'fclose' => 'filesystem', 'fread' => 'filesystem', 'fwrite' => 'filesystem',\n        'unlink' => 'filesystem', 'mkdir' => 'filesystem', 'scandir' => 'filesystem',\n    ];",
        $t
    );

    return str_replace(
        "'array_key_exists' => 'array', 'array_map' => 'array',\n    ];",
        "'array_key_exists' => 'array', 'array_map' => 'array',\n        'array_combine' => 'array', 'array_count' => 'array', 'array_filter' => 'array', 'array_flip' => 'array',\n        'array_pop' => 'array', 'array_product' => 'array', 'array_push' => 'array', 'array_reverse' => 'array',\n        'array_shift' => 'array', 'array_sum' => 'array', 'array_unique' => 'array', 'sort' => 'array',\n        'compact' => 'array', 'extract' => 'array', 'defined' => 'array', 'define' => 'array',\n    ];",
        $t
    );
});

echo "done\n";

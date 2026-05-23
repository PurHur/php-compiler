#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

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

function stripPrivateJitStringArg(string $text): string
{
    return (string) preg_replace(
        '/\n\s*private static function jitStringArg\(Context \$context, JITVariable \$arg\): Value\n\{.*?\n\s*\}\n/s',
        "\n",
        $text,
        1
    );
}

patch('ext/standard/hash_.php', static function (string $t): string {
    $t = stripPrivateJitStringArg($t);

    return (string) preg_replace(
        '/return JitHash::hash\(\$context, JitStringArg::lower\(\$context, \$args\[0\], \'hash\(\) algorithm\'\), JitStringArg::lower\(\$context, \$args\[1\], \'hash\(\) data\'\), \$raw\);hash\([\s\S]*?\);\s*\}/',
        "return JitHash::hash(\$context, JitStringArg::lower(\$context, \$args[0], 'hash() algorithm'), JitStringArg::lower(\$context, \$args[1], 'hash() data'), \$raw);\n    }",
        $t,
        1
    );
});

patch('ext/standard/hash_hmac.php', static fn (string $t): string => stripPrivateJitStringArg((string) preg_replace(
    '/return JitHash::hashHmac\(\$context, JitStringArg::lower\(\$context, \$args\[0\], \'hash_hmac\(\) algorithm\'\), JitStringArg::lower\(\$context, \$args\[1\], \'hash_hmac\(\) data\'\), JitStringArg::lower\(\$context, \$args\[2\], \'hash_hmac\(\) key\'\), \$raw\);hashHmac\([\s\S]*?\);\s*\}/',
    "return JitHash::hashHmac(\$context, JitStringArg::lower(\$context, \$args[0], 'hash_hmac() algorithm'), JitStringArg::lower(\$context, \$args[1], 'hash_hmac() data'), JitStringArg::lower(\$context, \$args[2], 'hash_hmac() key'), \$raw);\n    }",
    $t,
    1
)));

foreach (['base64_encode' => 'JitBase64Encode::encode', 'base64_decode' => 'JitBase64Decode::decode'] as $name => $helper) {
    patch("ext/standard/{$name}.php", static function (string $t) use ($name, $helper): string {
        if (preg_match('/->jitString\s*\(|JitStringArg::lower/', $t)) {
            return $t;
        }

        return (string) preg_replace(
            '/public function call\(Context \$context, JITVariable \.\.\.\$args\): Value\n    \{[\s\S]*?\n    \}(?=\n(?:    |\}|\s*$))/',
            "public function call(Context \$context, JITVariable ...\$args): Value\n    {\n        if (1 !== \\count(\$args)) {\n            throw new \\LogicException('{$name}() requires exactly one argument in this compiler build');\n        }\n        \$str = \$this->jitString(\$context, \$args[0], '{$name}() argument #1');\n\n        return {$helper}(\$context, \$str);\n    }",
            $t,
            1
        );
    });
}

patch('ext/standard/str_replace.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }
    $t = stripPrivateJitStringArg($t);
    $t = (string) preg_replace('/foreach \(\$args as \$arg\) \{[\s\S]*?\}\n\n/', '', $t, 1);

    return str_replace(
        ['self::jitStringArg($context, $args[0])', 'self::jitStringArg($context, $args[1])', 'self::jitStringArg($context, $args[2])'],
        ["\$this->jitString(\$context, \$args[0], 'str_replace() search')", "\$this->jitString(\$context, \$args[1], 'str_replace() replace')", "\$this->jitString(\$context, \$args[2], 'str_replace() subject')"],
        $t
    );
});

patch('ext/standard/copy_.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }

    return (string) preg_replace(
        '/public function call\(Context \$context, JITVariable \.\.\.\$args\): Value\n    \{[\s\S]*?\n    \}/',
        "public function call(Context \$context, JITVariable ...\$args): Value\n    {\n        if (2 !== \\count(\$args)) {\n            throw new \\LogicException('copy() requires exactly two arguments in this compiler build');\n        }\n        \$a = \$this->jitString(\$context, \$args[0], 'copy() argument #1');\n        \$b = \$this->jitString(\$context, \$args[1], 'copy() argument #2');\n\n        return JitCopy::invoke(\$context, \$a, \$b);\n    }",
        $t,
        1
    );
});

patch('ext/standard/chr.php', static function (string $t): string {
    if (str_contains($t, 'JitLongArg::lower')) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return (string) preg_replace(
        "/if \(JITVariable::TYPE_NATIVE_LONG !== \\\$args\[0\]->type\) \{[\s\S]*?\}\n\s*\\\$v = \\\$context->helper->loadValue\(\\\$args\[0\]\);/",
        "\$v = JitLongArg::lower(\$context, \$args[0], 'chr() codepoint');",
        $t,
        1
    );
});

foreach (['decbin', 'dechex', 'decoct'] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn): string {
        if (str_contains($t, 'JitLongArg::lower')) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

        return str_replace('$v = $context->helper->loadValue($args[0]);', "\$v = JitLongArg::lower(\$context, \$args[0], '{$fn}() argument #1');", $t);
    });
}

patch('ext/standard/str_rot13.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }

    return str_replace(
        'return JitStrRot13::rot13($context, $args[0]);',
        "\$str = \$this->jitString(\$context, \$args[0], 'str_rot13() string');\n        \$copy = \$context->builder->call(\$context->lookupFunction('__string__separate'), \$str);\n        JitStrRot13::transformInPlace(\$context, \$copy);\n\n        return \$copy;",
        $t
    );
});

patch('ext/standard/strchr.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }

    return str_replace(
        'return self::delegate()->call($context, ...$args);',
        <<<'PHP'
$argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strchr() requires two or three arguments in this compiler build');
        }
        $before = null;
        if (3 === $argc) {
            $before = $this->jitBool($context, $args[2], 'strchr() before_needle');
        }

        return JitStrstr::find(
            $context,
            $this->jitString($context, $args[0], 'strchr() argument #1'),
            $this->jitString($context, $args[1], 'strchr() argument #2'),
            $before
        );
PHP,
        $t
    );
});

patch('ext/standard/strip_tags.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }

    return str_replace(
        'return JitStripTags::stripTags($context, $args[0], $allowed);',
        <<<'PHP'
$subject = $this->jitString($context, $args[0], 'strip_tags() string');
        if (null === $allowed) {
            $allowPtr = $context->builder->load($context->constantStringFromString(''));
        } else {
            $allowPtr = $this->jitString($context, $allowed, 'strip_tags() allowed_tags');
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_strip_tags'),
            $subject,
            $allowPtr
        );
PHP,
        $t
    );
});

$jitSprintf = $root.'/ext/standard/JitSprintf.php';
$js = (string) file_get_contents($jitSprintf);
if (!str_contains($js, 'formatWithFmt')) {
    file_put_contents($jitSprintf, str_replace(
        "final class JitSprintf\n{",
        <<<'PHP'
final class JitSprintf
{
    public static function formatWithFmt(Context $context, Value $fmt, JITVariable ...$args): Value
    {
        $wrapped = [new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $fmt)];

        return self::format($context, ...array_merge($wrapped, $args));
    }

PHP,
        $js
    ));
    echo "patched ext/standard/JitSprintf.php\n";
}

patch('ext/standard/sprintf_.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }

    return str_replace(
        'return JitSprintf::format($context, ...$args);',
        "return JitSprintf::formatWithFmt(\$context, \$this->jitString(\$context, \$args[0], 'sprintf() format'), ...\\array_slice(\$args, 1));",
        $t
    );
});

foreach (['in_array', 'array_search'] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn): string {
        if (!preg_match('/public function call\(/', $t) || preg_match('/->jitString\s*\(/', $t)) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitBoolArg;');

        return (string) preg_replace(
            '/(\$strict = \$context->constantFromBool\(false\);\s*)if \(3 === \\\\count\(\$args\)\) \{[\s\S]*?\}(\s*\n\s*return ArrayBuiltinHelper::)/',
            "\$1if (3 === \\count(\$args)) {\n            \$strict = JitBoolArg::lower(\$context, \$args[2], '{$fn}() strict');\n        }\n        if (JITVariable::TYPE_STRING === \$args[0]->type || JITVariable::TYPE_VALUE === \$args[0]->type) {\n            \$this->jitString(\$context, \$args[0], '{$fn}() needle');\n        }\$2",
            $t,
            1
        );
    });
}

patch('ext/standard/array_fill.php', static function (string $t): string {
    if (str_contains($t, 'JitLongArg::lower')) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return str_replace(
        '$startIndex = $context->helper->loadValue($args[0]);'."\n        ".'$count = $context->helper->loadValue($args[1]);',
        "\$startIndex = JitLongArg::lower(\$context, \$args[0], 'array_fill() start index');\n        \$count = JitLongArg::lower(\$context, \$args[1], 'array_fill() count');",
        $t
    );
});

patch('ext/standard/array_slice.php', static function (string $t): string {
    if (str_contains($t, 'JitLongArg::lower')) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
    $t = (string) preg_replace('/\n\s*private static function jitSignedI64\(Context \$context, JITVariable \$arg\): Value\n\{[\s\S]*?\n\s*\}\n/s', "\n", $t);
    $t = str_replace('self::jitSignedI64($context, $args[1])', "JitLongArg::lower(\$context, \$args[1], 'array_slice() offset')", $t);
    $t = str_replace('self::jitSignedI64($context, $args[2])', "JitLongArg::lower(\$context, \$args[2], 'array_slice() length')", $t);

    return $t;
});

patch('ext/standard/array_key_exists.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
    $t = preg_replace('/\$context->helper->loadValue\(\$key\)/', '$this->jitString($context, $key, \'array_key_exists() key\')', $t, 1);
    $t = str_replace(
        '$context->builder->truncOrBitCast(
                    $context->helper->loadValue($key),',
        '$context->builder->truncOrBitCast(
                    JitLongArg::lower($context, $key, \'array_key_exists() key\'),',
        $t
    );
    $t = str_replace('$index = $context->helper->loadValue($key);', '$index = JitLongArg::lower($context, $key, \'array_key_exists() key\');', $t);

    return (string) $t;
});

foreach ([
    ['intval.php', "case JITVariable::TYPE_STRING:\n                return \$this->stringToInt(\$context, \$v);", "case JITVariable::TYPE_STRING:\n                return \$this->stringToInt(\$context, \$this->jitString(\$context, \$args[0], 'intval() argument #1'));"],
    ['floatval.php', "case JITVariable::TYPE_STRING:\n                \$ptr = \$this->stringDataPtr(\$context, \$v);", "case JITVariable::TYPE_STRING:\n                \$ptr = \$this->stringDataPtr(\$context, \$this->jitString(\$context, \$args[0], 'floatval() argument #1'));"],
    ['is_numeric.php', "case JITVariable::TYPE_STRING:\n                return \$this->stringIsNumeric(\$context, \$context->helper->loadValue(\$args[0]));", "case JITVariable::TYPE_STRING:\n                return \$this->stringIsNumeric(\$context, \$this->jitString(\$context, \$args[0], 'is_numeric() argument #1'));"],
] as [$file, $from, $to]) {
    patch('ext/standard/'.$file, static function (string $t) use ($from, $to): string {
        return preg_match('/->jitString\s*\(/', $t) ? $t : str_replace($from, $to, $t);
    });
}

patch('ext/standard/number_format.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return str_replace(
        'return JitNumberFormat::format($context, ...$args);',
        <<<'PHP'
$argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }
        if ($argc >= 2) {
            JitLongArg::lower($context, $args[1], 'number_format() decimals');
        }
        if ($argc >= 3) {
            $this->jitString($context, $args[2], 'number_format() decimal separator');
        }
        if ($argc >= 4) {
            $this->jitString($context, $args[3], 'number_format() thousands separator');
        }

        return JitNumberFormat::format($context, ...$args);
PHP,
        $t
    );
});

patch('ext/standard/json_encode.php', static function (string $t): string {
    if (preg_match('/JitStringArg::lower|->jitString\s*\(/', $t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitStringArg;');

    return str_replace(
        'return JitJsonEncode::encode($context, $args[0]);',
        <<<'PHP'
$literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $encoded = \json_encode($literal);
            if (false === $encoded) {
                throw new \LogicException('json_encode() failed');
            }

            return $context->builder->load($context->constantStringFromString($encoded));
        }

        return JitJsonEncode::encode($context, $args[0]);
PHP,
        $t
    );
});

patch('ext/standard/abs.php', static function (string $t): string {
    if (str_contains($t, 'JitLongArg::lower')) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

    return str_replace(
        "case JITVariable::TYPE_NATIVE_LONG:\n                \$zero = \$v->typeOf()->constInt(0, false);",
        "case JITVariable::TYPE_NATIVE_LONG:\n                \$v = JitLongArg::lower(\$context, \$args[0], 'abs() argument #1');\n                \$zero = \$v->typeOf()->constInt(0, false);",
        $t
    );
});

foreach (['ceil', 'floor', 'round'] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn): string {
        if (str_contains($t, 'JitLongArg::lower')) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

        return str_replace(
            "case JITVariable::TYPE_NATIVE_LONG:\n                \$asFloat = \$context->builder->siToFp(\$v, \$double);",
            "case JITVariable::TYPE_NATIVE_LONG:\n                \$asFloat = \$context->builder->siToFp(JitLongArg::lower(\$context, \$args[0], '{$fn}() argument #1'), \$double);",
            $t
        );
    });
}

foreach (['sqrt', 'sin', 'cos', 'tan', 'log', 'rad2deg', 'deg2rad', 'intdiv'] as $fn) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn): string {
        if (str_contains($t, 'JitLongArg::lower')) {
            return $t;
        }
        $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');

        return str_replace('$v = $context->helper->loadValue($args[0]);', "\$v = JitLongArg::lower(\$context, \$args[0], '{$fn}() argument #1');", $t);
    });
}

patch('ext/standard/strval.php', static function (string $t): string {
    return preg_match('/->jitString\s*\(/', $t) ? $t : str_replace(
        "case JITVariable::TYPE_STRING:\n                return \$context->helper->loadValue(\$args[0]);",
        "case JITVariable::TYPE_STRING:\n                return \$this->jitString(\$context, \$args[0], 'strval() argument #1');",
        $t
    );
});

patch('ext/standard/file_put_contents.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }
    $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
    $t = (string) preg_replace('/if \(JITVariable::TYPE_STRING !== \$args\[0\]->type\) \{[\s\S]*?\}\n\s*if \(JITVariable::TYPE_STRING !== \$args\[1\]->type\) \{[\s\S]*?\}\n\s*/', '', $t);
    $t = str_replace(
        '$context->helper->loadValue($args[0]),
            $context->helper->loadValue($args[1]),',
        '$this->jitString($context, $args[0], \'file_put_contents() filename\'),
            $this->jitString($context, $args[1], \'file_put_contents() data\'),',
        $t
    );
    $t = str_replace('$context->helper->loadValue($args[2])', 'JitLongArg::lower($context, $args[2], \'file_put_contents() flags\')', $t);

    return $t;
});

foreach (['rename_' => 'JitRename::invoke', 'mkdir_' => 'JitMkdir::invoke', 'touch_' => 'JitTouch::invoke'] as $file => $helper) {
    $name = rtrim($file, '_');
    patch("ext/standard/{$file}.php", static function (string $t) use ($name, $helper): string {
        if (preg_match('/->jitString\s*\(/', $t)) {
            return $t;
        }

        return (string) preg_replace(
            '/public function call\(Context \$context, JITVariable \.\.\.\$args\): Value\n    \{[\s\S]*?\n    \}/',
            "public function call(Context \$context, JITVariable ...\$args): Value\n    {\n        if (2 !== \\count(\$args)) {\n            throw new \\LogicException('{$name}() requires exactly two arguments in this compiler build');\n        }\n        \$a = \$this->jitString(\$context, \$args[0], '{$name}() argument #1');\n        \$b = \$this->jitString(\$context, \$args[1], '{$name}() argument #2');\n\n        return {$helper}(\$context, \$a, \$b);\n    }",
            $t,
            1
        );
    });
}

foreach (['putenv_', 'chmod_', 'glob_', 'parse_url', 'pathinfo', 'phpc_deploy_path', 'random_bytes', 'var_export', 'filter_var', 'filter_input', 'date', 'gmdate', 'scandir'] as $base) {
    $rel = "ext/standard/{$base}.php";
    if (!is_file($root.'/'.$rel)) {
        continue;
    }
    $name = str_replace('_', '', $base);
    patch($rel, static function (string $t) use ($name): string {
        if (preg_match('/->jitString\s*\(|JitStringArg::lower/', $t) || !preg_match('/public function call\(/', $t)) {
            return $t;
        }

        return (string) preg_replace('/(\n        return )/', "\n        \$this->jitString(\$context, \$args[0], '{$name}() argument #1');$1", $t, 1);
    });
}

patch('ext/standard/array_map.php', static function (string $t): string {
    if (preg_match('/->jitString\s*\(/', $t)) {
        return $t;
    }

    return str_replace(
        'return ArrayBuiltinHelper::buildMapArray($context, $args[0], $args[1]);',
        "if (JITVariable::TYPE_STRING === \$args[0]->type || JITVariable::TYPE_VALUE === \$args[0]->type) {\n            \$this->jitString(\$context, \$args[0], 'array_map() callback');\n        }\n\n        return ArrayBuiltinHelper::buildMapArray(\$context, \$args[0], \$args[1]);",
        $t
    );
});

foreach (['web_string' => 'webString', 'web_int' => 'webInt', 'web_bool' => 'webBool'] as $fn => $method) {
    patch("ext/standard/{$fn}.php", static function (string $t) use ($fn, $method): string {
        if (preg_match('/->jitString\s*\(|JitLongArg::lower/', $t)) {
            return $t;
        }
        if ('web_int' === $fn) {
            $t = ensureUse($t, 'use PHPCompiler\\JIT\\JitLongArg;');
            $insert = "\$this->jitString(\$context, \$args[1], 'web_int() key');\n        JitLongArg::lower(\$context, \$args[2], 'web_int() default');\n\n        ";
        } else {
            $insert = "\$this->jitString(\$context, \$args[1], '{$fn}() key');\n\n        ";
        }

        return str_replace("return JitWebParams::{$method}(\$context, ...\$args);", $insert."return JitWebParams::{$method}(\$context, ...\$args);", $t);
    });
}

echo "done\n";

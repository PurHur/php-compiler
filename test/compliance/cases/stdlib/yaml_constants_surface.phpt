--TEST--
stdlib YAML_* encoding/break/scalar/tag constants when yaml advertised (#27873)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('yaml') ? "ext=Y\n" : "ext=N\n";
foreach ([
    'YAML_ANY_ENCODING', 'YAML_UTF8_ENCODING', 'YAML_UTF16LE_ENCODING', 'YAML_UTF16BE_ENCODING',
    'YAML_ANY_BREAK', 'YAML_CR_BREAK', 'YAML_LN_BREAK', 'YAML_CRLN_BREAK',
    'YAML_ANY_SCALAR_STYLE', 'YAML_PLAIN_SCALAR_STYLE', 'YAML_DOUBLE_QUOTED_SCALAR_STYLE',
    'YAML_NULL_TAG', 'YAML_PHP_TAG',
] as $c) {
    echo $c, '=', defined($c) ? var_export(constant($c), true) : 'MISSING', "\n";
}
$out = yaml_emit(['a' => 1], YAML_UTF8_ENCODING, YAML_LN_BREAK);
echo str_contains($out, "---\n") ? "emit_ok\n" : "emit_bad\n";
?>
--EXPECT--
ext=Y
YAML_ANY_ENCODING=0
YAML_UTF8_ENCODING=1
YAML_UTF16LE_ENCODING=2
YAML_UTF16BE_ENCODING=3
YAML_ANY_BREAK=0
YAML_CR_BREAK=1
YAML_LN_BREAK=2
YAML_CRLN_BREAK=3
YAML_ANY_SCALAR_STYLE=0
YAML_PLAIN_SCALAR_STYLE=1
YAML_DOUBLE_QUOTED_SCALAR_STYLE=3
YAML_NULL_TAG='tag:yaml.org,2002:null'
YAML_PHP_TAG='!php/object'
emit_ok

--TEST--
stdlib IDNA_ERROR_* bitflag globals (#24096, php-src ext/intl/idn/idn.c)
--SKIPIF--
<?php
if (!extension_loaded('intl') || !function_exists('idn_to_ascii')) {
    echo 'skip intl idn required';
}
--FILE--
<?php
declare(strict_types=1);

$names = [
    'IDNA_ERROR_EMPTY_LABEL' => 1,
    'IDNA_ERROR_LABEL_TOO_LONG' => 2,
    'IDNA_ERROR_DOMAIN_NAME_TOO_LONG' => 4,
    'IDNA_ERROR_LEADING_HYPHEN' => 8,
    'IDNA_ERROR_TRAILING_HYPHEN' => 16,
    'IDNA_ERROR_HYPHEN_3_4' => 32,
    'IDNA_ERROR_LEADING_COMBINING_MARK' => 64,
    'IDNA_ERROR_DISALLOWED' => 128,
    'IDNA_ERROR_PUNYCODE' => 256,
    'IDNA_ERROR_LABEL_HAS_DOT' => 512,
    'IDNA_ERROR_INVALID_ACE_LABEL' => 1024,
    'IDNA_ERROR_BIDI' => 2048,
    'IDNA_ERROR_CONTEXTJ' => 4096,
];
$ok = 0;
foreach ($names as $name => $want) {
    if (defined($name) && constant($name) === $want) {
        ++$ok;
    }
}
echo $ok, "\n";
$intl = get_defined_constants(true)['intl'] ?? [];
$err = array_filter(array_keys($intl), static fn ($k) => str_starts_with($k, 'IDNA_ERROR_'));
sort($err);
echo count($err), "\n";
echo (int) in_array('IDNA_ERROR_EMPTY_LABEL', $err, true), "\n";
--EXPECT--
13
13
1

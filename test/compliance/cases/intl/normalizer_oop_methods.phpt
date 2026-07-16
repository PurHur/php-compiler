--TEST--
Normalizer OOP static methods match procedural (#19535, ext/intl/normalizer)
--SKIPIF--
<?php
if (!class_exists('Normalizer', false) || !method_exists('Normalizer', 'normalize')) {
    die('skip Normalizer OOP not advertised');
}
?>
--FILE--
<?php
declare(strict_types=1);

$composed = "\xC3\xA9";
$decomposed = "e\xCC\x81";

echo method_exists('Normalizer', 'normalize') ? "yes\n" : "no\n";
echo method_exists('Normalizer', 'isNormalized') ? "yes\n" : "no\n";
echo method_exists('Normalizer', 'getRawDecomposition') ? "yes\n" : "no\n";

echo bin2hex(Normalizer::normalize($decomposed, Normalizer::FORM_C)), "\n";
echo Normalizer::normalize($composed, Normalizer::FORM_C) === normalizer_normalize($composed, Normalizer::FORM_C)
    ? "oop_proc_match\n" : "oop_proc_mismatch\n";
echo Normalizer::isNormalized($composed, Normalizer::FORM_C) ? "norm\n" : "not\n";
echo Normalizer::isNormalized($decomposed, Normalizer::FORM_C) ? "decomposed_norm\n" : "decomposed_not\n";

$raw = Normalizer::getRawDecomposition($composed);
echo null === $raw ? "raw_null\n" : ("raw_" . bin2hex($raw) . "\n");
$none = Normalizer::getRawDecomposition('a');
echo null === $none ? "a_null\n" : ("a_" . bin2hex($none) . "\n");
$bad = Normalizer::getRawDecomposition('ab');
echo null === $bad ? "bad_null\n" : "bad_str\n";
echo function_exists('normalizer_get_raw_decomposition') ? "proc_raw_yes\n" : "proc_raw_no\n";
?>
--EXPECT--
yes
yes
yes
c3a9
oop_proc_match
norm
decomposed_not
raw_65cc81
a_null
bad_null
proc_raw_yes

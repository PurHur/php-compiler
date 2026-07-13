<?php
declare(strict_types=1);

echo function_exists('normalizer_normalize') ? "yes\n" : "no\n";
echo class_exists('Normalizer', false) ? "class\n" : "no_class\n";

$composed = "\xC3\xA9";
$decomposed = "e\xCC\x81";

echo bin2hex(normalizer_normalize($decomposed, Normalizer::FORM_C)), "\n";
echo normalizer_normalize($composed, Normalizer::FORM_C) === $composed ? "stable\n" : "changed\n";
echo normalizer_is_normalized($composed, Normalizer::FORM_C) ? "norm\n" : "not\n";

try {
    normalizer_normalize('x', 99);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

<?php

declare(strict_types=1);

// Phantom builtins: callable on 8.4-capable profile without ext/intl; function_exists stays false (#17010).
echo grapheme_strimwidth('こんにちは', 0, 3), "\n";
echo grapheme_strimwidth('hello', 0, 10), "\n";

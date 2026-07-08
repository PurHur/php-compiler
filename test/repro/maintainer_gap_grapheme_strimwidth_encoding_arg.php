<?php

declare(strict_types=1);

// Issue #17342: 4th parameter is encoding, not trimmarker.
echo grapheme_strimwidth('日本語テスト', 0, 4, 'UTF-8'), "\n";
echo grapheme_strimwidth('hello', 0, 10), "\n";

<?php

declare(strict_types=1);

// Literal invalid UTF-8 so AOT can constant-fold with JSON_INVALID_UTF8_IGNORE (#21723).
echo json_encode("a\x80b", JSON_INVALID_UTF8_IGNORE), "\n";
echo json_encode("\xC3\x28", JSON_INVALID_UTF8_IGNORE), "\n";

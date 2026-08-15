<?php

declare(strict_types=1);

// Maintainer gap repro for #31287 — str_word_count(null $format) under strict_types.
str_word_count('a b', null);

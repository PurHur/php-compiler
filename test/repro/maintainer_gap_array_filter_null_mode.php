<?php

declare(strict_types=1);

// Maintainer gap repro for #31360 — array_filter(null $mode) under strict_types.
array_filter([0, 1, 2], null, null);

<?php
/** AOT probe #22242 — method advertisement only (no live generator). */
declare(strict_types=1);
echo method_exists(ReflectionGenerator::class, 'isClosed') ? "yes\n" : "no\n";

<?php
/**
 * Repro for #20823 — Spoofchecker::setAllowedChars() (php-src spoofchecker_main.cpp).
 * Requires PHP_COMPILER_PROFILE=8.4 (#23157 — method withheld on default/8.2).
 *
 * Prefer instance method_exists over Spoofchecker::class before construct: early
 * class_exists can withhold Spoofchecker when host php-intl is absent (#19670).
 */
$s = new Spoofchecker();
echo 'method=', (int) method_exists($s, 'setAllowedChars'), "\n";
echo 'ignore=', Spoofchecker::IGNORE_SPACE, "\n";
$s->setAllowedChars('[a-z0-9]');
echo 'hello=', (int) $s->isSuspicious('hello'), "\n";
echo 'accent=', (int) $s->isSuspicious("h\xC3\xA9llo"), "\n";

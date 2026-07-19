<?php
/**
 * Repro for #20823 — Spoofchecker::setAllowedChars() (php-src spoofchecker_main.cpp).
 *
 * Prefer instance method_exists over Spoofchecker::class before construct: early
 * ClassConstFetch can trip jit.php WeakRef NestedJIT bootstrap on some hosts.
 */
$s = new Spoofchecker();
echo 'method=', (int) method_exists($s, 'setAllowedChars'), "\n";
echo 'ignore=', Spoofchecker::IGNORE_SPACE, "\n";
$s->setAllowedChars('[a-z0-9]');
var_dump($s->isSuspicious('hello'));
var_dump($s->isSuspicious("h\xC3\xA9llo"));

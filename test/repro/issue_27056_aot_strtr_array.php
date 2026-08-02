<?php
/**
 * Issue #27056 — AOT strtr(array pairs) must not segfault after build.
 */
echo strtr('hi', ['h' => 'H', 'i' => 'I']), PHP_EOL;
echo strtr('hi', 'hi', 'HI'), PHP_EOL;

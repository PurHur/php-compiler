<?php
/**
 * Repro #30812 — thin AOT wordwrap must match Zend without SIGSEGV.
 */
echo wordwrap('abc def ghi', 3, '|', true), "\n";
echo wordwrap('hello world foo', 5, '|', false), "\n";
echo wordwrap('verylongword', 5, '|', true), "\n";

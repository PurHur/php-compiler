<?php

/**
 * Issue #26897 — AOT similar_text() by-ref $percent (and similarity int) must match Zend.
 *
 * Thin AOT NestedJIT of SimilarTextJitHelper must not call VmString (ExternalMethod stub → 0).
 */
similar_text('hello', 'hallo', $p);
echo (int) $p, "\n";
echo similar_text('hello', 'hallo'), "\n";
echo similar_text('Hello World', 'Hello PHP'), "\n";

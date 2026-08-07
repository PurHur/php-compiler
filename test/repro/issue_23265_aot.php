<?php

declare(strict_types=1);

/**
 * Issue #23265 — AOT path: Zend named flags/string for HTML decode helpers.
 * (Passing a non-null encoding string hits a pre-existing html_entity_decode
 * AOT helper segfault unrelated to named-arg rename; encoding:null is fine.)
 */
echo htmlspecialchars_decode(string: '&amp;', flags: ENT_QUOTES), "\n";
echo html_entity_decode(string: '&amp;', flags: ENT_QUOTES, encoding: null), "\n";

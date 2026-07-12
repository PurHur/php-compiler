<?php
// Issue #18368 — urlencode()/rawurlencode() null coerces to '' (ext/standard/url.c).
echo 'urlencode=' . urlencode(null) . "\n";
echo 'rawurlencode=' . rawurlencode(null) . "\n";

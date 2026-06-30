<?php
// Issue #13948 — html_entity_decode('&apos;', ENT_QUOTES|ENT_HTML401) leaves entity unchanged.
echo html_entity_decode('&apos;', ENT_QUOTES | ENT_HTML401), "\n";

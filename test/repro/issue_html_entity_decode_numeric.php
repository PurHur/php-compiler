<?php
// Issue #11510 — html_entity_decode() numeric character references.
echo html_entity_decode('&#65;', ENT_QUOTES | ENT_HTML5), "\n";
echo html_entity_decode('&#x41;', ENT_QUOTES | ENT_HTML5), "\n";

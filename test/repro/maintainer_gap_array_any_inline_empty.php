<?php
// Issue #11729 — array_all()/array_any() vacuous truth on inline [] haystack.
echo 'all='.(array_all([], fn ($v) => (bool) $v) ? 'true' : 'false'), "\n";
echo 'any='.(array_any([], fn ($v) => (bool) $v) ? 'true' : 'false'), "\n";

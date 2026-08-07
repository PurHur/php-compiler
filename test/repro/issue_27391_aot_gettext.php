<?php

declare(strict_types=1);

// AOT gettext() must not segfault (#27391) — identity msgid when no MO catalog.
var_dump(function_exists('gettext'));
echo gettext('hello'), "\n";

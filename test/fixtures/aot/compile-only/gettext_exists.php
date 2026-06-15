<?php
// Compile-only (#3449): gettext builtins register for AOT link inventory.
echo extension_loaded('gettext') ? "gettext-loaded\n" : "gettext-missing\n";
bindtextdomain('messages', __DIR__);
echo gettext('Hello'), "\n";

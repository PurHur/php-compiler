<?php

declare(strict_types=1);

// Compile-only (#7230): RequestMethod string-backed enum must compile through AOT.
echo enum_exists('RequestMethod', false) ? "yes\n" : "no\n";
echo RequestMethod::Post->value, "\n";
echo RequestMethod::Get->value, "\n";

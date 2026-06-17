<?php

declare(strict_types=1);

// Compile-only (#6591): http_response_code() unset/first-set semantics for AOT lowering.
echo http_response_code() ? 'true' : 'false', "\n";
echo http_response_code(404) ? 'true' : 'false', "\n";
echo http_response_code(), "\n";

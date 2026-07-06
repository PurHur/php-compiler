<?php

declare(strict_types=1);

/** Issue #16734: user-script AOT must run wordwrap() without SIGSEGV. */

echo wordwrap('hi', 2, '-');

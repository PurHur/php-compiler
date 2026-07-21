<?php
// AOT compile-only (#17270 / #21515): substr_compare() null TypeError under strict_types.
declare(strict_types=1);
substr_compare(null, 'a', 0);

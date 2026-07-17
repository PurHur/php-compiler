<?php
// AOT compile-only (#20164): substr_compare() null TypeError guard on 8.4 forward profile.
declare(strict_types=1);
substr_compare(null, 'a', 0);

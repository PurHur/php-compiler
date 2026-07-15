<?php
// AOT compile-only (#18980): substr() null TypeError guard on 8.4 forward profile.
declare(strict_types=1);
substr(null, 0);

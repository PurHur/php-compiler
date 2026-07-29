<?php
// AOT compile-only (#18980): under declare(strict_types=1), substr(null) TypeErrors at runtime.
// Non-strict PROFILE=8.4 soft-null is #24817 (see substr_null_forward_profile.phpt).
declare(strict_types=1);
substr(null, 0);

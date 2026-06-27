<?php

declare(strict_types=1);

/** Issue #12413 — named exit(status:) must not compile on Zend 8.2 reference profile. */
exit(status: 0);

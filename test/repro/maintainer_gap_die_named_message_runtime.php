<?php

declare(strict_types=1);

/** Issue #12435 — named die(message:) must not compile on Zend 8.2 reference profile. */
die(message: 'bye');

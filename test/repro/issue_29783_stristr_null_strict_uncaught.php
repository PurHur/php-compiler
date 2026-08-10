<?php

declare(strict_types=1);

// Uncaught TypeError for AOT smoke (#29783).
stristr(null, 'a');

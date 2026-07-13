<?php

declare(strict_types=1);

// Forward-profile phantom gate: PHP 8.4 DOM methods must not appear on 8.2 reference profile (#17470, #18636).
// Run: PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/maintainer_gap_dom_node_phantom_reference.php
require __DIR__.'/maintainer_gap_dom_node_methods_reference_profile.php';

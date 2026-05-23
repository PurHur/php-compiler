<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: DeployRoot::resolvePathWithSuffix (ConstStringFolder::foldDeployPathConcat).
 */

require_once __DIR__.'/../../lib/Web/DeployRoot.php';

use PHPCompiler\Web\DeployRoot;

DeployRoot::resolvePathWithSuffix(
    'templates',
    __DIR__.'/deploy_path_data/templates',
    '/marker.php'
);
echo "1\n";

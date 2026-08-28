<?php

declare(strict_types=1);

// Probe ErrorSilenceJitHelper static init on thin AOT (#35563 regression).
use PHPCompiler\ext\standard\ErrorSilenceJitHelper;

var_export(ErrorSilenceJitHelper::isErrorLevelEnabled(2));
echo "\n";
var_export(ErrorSilenceJitHelper::getErrorReporting());
echo "\n";

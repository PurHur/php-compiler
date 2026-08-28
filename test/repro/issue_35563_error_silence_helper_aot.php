<?php

declare(strict_types=1);

// Probe compiled-module error_reporting seed on thin AOT (#35563 regression).
// Uses the SilenceRuntime LLVM global bridge — not direct ErrorSilenceJitHelper statics.
var_export((error_reporting() & 2) !== 0);
echo "\n";
var_export(error_reporting());
echo "\n";

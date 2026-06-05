<?php
var_export(class_exists('CompileError'));
echo "\n";
try {
    throw new CompileError('probe');
} catch (CompileError $e) {
    echo "CompileError\n";
} catch (Error $e) {
    echo "Error only\n";
}

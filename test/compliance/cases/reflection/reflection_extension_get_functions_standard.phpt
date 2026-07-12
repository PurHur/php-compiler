--TEST--
ReflectionExtension::getFunctions() hides __compiler_* and phantom builtins (#18357)
--FILE--
<?php
declare(strict_types=1);

$funcs = (new ReflectionExtension('standard'))->getFunctions();
echo count($funcs), "\n";
echo isset($funcs['__compiler_is_superglobal_name']) ? 'bad_compiler' : 'no_compiler', "\n";
echo isset($funcs['compiler_language_warning']) ? 'bad_clw' : 'no_clw', "\n";
echo isset($funcs['phpc_run_command']) ? 'bad_phpc' : 'no_phpc', "\n";
echo isset($funcs['hash']) ? 'bad_hash' : 'no_hash', "\n";
echo isset($funcs['spl_autoload']) ? 'bad_spl' : 'no_spl', "\n";
echo isset($funcs['rand']) ? 'bad_rand' : 'no_rand', "\n";
echo isset($funcs['is_array']) ? 'has_is_array' : 'no_is_array', "\n";
echo isset($funcs['strptime']) ? 'has_strptime' : 'no_strptime', "\n";
--EXPECT--
524
no_compiler
no_clw
no_phpc
no_hash
no_spl
no_rand
has_is_array
has_strptime

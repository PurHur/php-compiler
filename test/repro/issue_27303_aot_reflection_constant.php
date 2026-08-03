<?php

/**
 * Repro #27303 — AOT ReflectionConstant::getName()/getValue() empty vs VM/JIT.
 *
 * PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_27303_aot_reflection_constant.php
 * PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/rc.bin test/repro/issue_27303_aot_reflection_constant.php && /tmp/rc.bin
 */
$c = new ReflectionConstant('PHP_VERSION_ID');
echo $c->getName(), ' ', $c->getValue(), "\n";

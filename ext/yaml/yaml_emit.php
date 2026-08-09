<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmMath;

/** yaml_emit() — encode value as YAML (PECL yaml / yaml.c; #6275, #27873). */
final class yaml_emit extends YamlFunction
{
    public function __construct()
    {
        parent::__construct('yaml_emit');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'yaml_emit', 1);
        $this->requireAtMostArgCount($frame, 'yaml_emit', 4);
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 0;
        $linebreak = 0;
        if (\count($frame->calledArgs) >= 2) {
            $encoding = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'yaml_emit', 2, 'encoding');
        }
        if (\count($frame->calledArgs) >= 3) {
            $linebreak = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'yaml_emit', 3, 'linebreak');
        }
        // arg 4 ($callbacks) accepted for arity; ignored in v1 subset emitter.
        $frame->returnVar->string(VmYaml::emit($frame->calledArgs[0], $encoding, $linebreak));
    }
}

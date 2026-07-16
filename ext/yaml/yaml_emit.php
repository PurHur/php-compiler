<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\Frame;

/** yaml_emit() — encode value as YAML (PECL yaml / yaml.c; #6275). */
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
        $frame->returnVar->string(VmYaml::emit($frame->calledArgs[0]));
    }
}

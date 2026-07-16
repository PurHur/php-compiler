<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** yaml_parse() — decode YAML string (PECL yaml / yaml.c; #6275). */
final class yaml_parse extends YamlFunction
{
    public function __construct()
    {
        parent::__construct('yaml_parse');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'yaml_parse', 1);
        $this->requireAtMostArgCount($frame, 'yaml_parse', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'yaml_parse',
            0,
            'input'
        );
        $decoded = VmYaml::parse($input, $frame);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}

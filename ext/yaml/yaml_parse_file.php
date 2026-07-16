<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** yaml_parse_file() — decode YAML file (PECL yaml / yaml.c; #6275). */
final class yaml_parse_file extends YamlFunction
{
    public function __construct()
    {
        parent::__construct('yaml_parse_file');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'yaml_parse_file', 1);
        $this->requireAtMostArgCount($frame, 'yaml_parse_file', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'yaml_parse_file',
            0,
            'filename'
        );
        $decoded = VmYaml::parseFile($filename, $frame);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}

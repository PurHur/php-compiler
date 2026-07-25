<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** yaml_parse_url() — decode YAML from URL/stream path (PECL yaml / yaml.c; #22252). */
final class yaml_parse_url extends YamlFunction
{
    public function __construct()
    {
        parent::__construct('yaml_parse_url');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'yaml_parse_url', 1);
        $this->requireAtMostArgCount($frame, 'yaml_parse_url', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $url = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'yaml_parse_url',
            0,
            'url'
        );
        $decoded = VmYaml::parseUrl($url, $frame);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}

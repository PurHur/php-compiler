<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** yaml_emit_file() — encode value to YAML file (PECL yaml / yaml.c; #6275). */
final class yaml_emit_file extends YamlFunction
{
    public function __construct()
    {
        parent::__construct('yaml_emit_file');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'yaml_emit_file', 2);
        $this->requireAtMostArgCount($frame, 'yaml_emit_file', 5);
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'yaml_emit_file',
            0,
            'filename'
        );
        $frame->returnVar->bool(VmYaml::emitFile($filename, $frame->calledArgs[1], $frame));
    }
}

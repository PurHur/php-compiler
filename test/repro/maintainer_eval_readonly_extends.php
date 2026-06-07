<?php
class ParentNormal {}
eval('readonly class ChildReadonly extends ParentNormal { public function __construct(public int $x = 1) {} }');
echo "allowed\n";

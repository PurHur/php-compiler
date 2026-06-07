<?php
eval('readonly class ParentReadonly {} class ChildNormal extends ParentReadonly {}');
echo "allowed\n";

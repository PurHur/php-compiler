--TEST--
RecursiveTreeIterator traversal hooks exist; subclass overrides fire (#20146, ext/spl/spl_iterators.c)
--FILE--
<?php
$methods = ['beginChildren', 'endChildren', 'beginIteration', 'endIteration', 'nextElement'];
foreach ($methods as $m) {
    echo $m, '=', method_exists('RecursiveTreeIterator', $m) ? 'Y' : 'N', "\n";
}
class HookRTI extends RecursiveTreeIterator {
    public array $log = [];
    public function beginChildren(): void { $this->log[] = 'beginChildren@'.$this->getDepth(); }
    public function endChildren(): void { $this->log[] = 'endChildren@'.$this->getDepth(); }
    public function beginIteration(): void { $this->log[] = 'beginIteration'; }
    public function endIteration(): void { $this->log[] = 'endIteration'; }
    public function nextElement(): void { $this->log[] = 'nextElement@'.$this->getDepth().':'.$this->getEntry(); }
}
$it = new HookRTI(new RecursiveArrayIterator([1, [2, 3], 4]));
$seen = [];
foreach ($it as $v) {
    $seen[] = $it->getEntry().'@'.$it->getDepth();
}
echo 'walk=', implode('|', $seen), "\n";
echo 'log=', implode('|', $it->log), "\n";
--EXPECT--
beginChildren=Y
endChildren=Y
beginIteration=Y
endIteration=Y
nextElement=Y
walk=1@0|Array@0|2@1|3@1|4@0
log=beginIteration|nextElement@0:1|nextElement@0:Array|beginChildren@1|nextElement@1:2|nextElement@1:3|endChildren@1|nextElement@0:4|endIteration

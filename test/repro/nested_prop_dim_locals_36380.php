<?php
/**
 * Object-property dim write with literal key + local array RHS (#36380).
 *
 * Literal keys live in Block::$constants; a later local array CV can alias the same
 * scope slot. FETCH_DIM_W must keep the constant key (Zend IS_CONST), not the array.
 *
 * php-src: Zend/zend_execute.c zend_fetch_dimension_address / IS_CONST keys.
 */
class PropDimLiteralKeyArray36380
{
    public $DefinitionData = array();

    public function viaLocals()
    {
        $id = strtolower('double quotes');
        $Data = array(
            'url' => 'http://example.com',
            'title' => 'example title',
        );
        $this->DefinitionData['Reference'][$id] = $Data;
    }

    public function viaInlineArray()
    {
        $this->DefinitionData['Reference']['foo'] = array('url' => 'u');
    }
}

$a = new PropDimLiteralKeyArray36380();
$a->viaLocals();
echo 'locals=' . (isset($a->DefinitionData['Reference']['double quotes']) ? '1' : '0') . "\n";
if (isset($a->DefinitionData['Reference']['double quotes'])) {
    echo 'url=' . $a->DefinitionData['Reference']['double quotes']['url'] . "\n";
} else {
    echo "url=\n";
}

$b = new PropDimLiteralKeyArray36380();
$b->viaInlineArray();
echo 'inline=' . (isset($b->DefinitionData['Reference']['foo']) ? '1' : '0') . "\n";

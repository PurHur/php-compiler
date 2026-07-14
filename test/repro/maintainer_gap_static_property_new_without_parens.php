<?php

class Node {
    public function __construct(public string $label = 'nil') {}
}

class Tree {
    public static Node $nil = new Node;
}

echo get_class(Tree::$nil), "\n";
echo Tree::$nil->label === 'nil' ? "1\n" : "0\n";
echo Tree::$nil === Tree::$nil ? "1\n" : "0\n";

    /**
     * PHP 8.0+ union RHS: $obj instanceof (A|B). Older php-parser emits BitwiseOr of ConstFetch (#3461).
     */
    private function parseInstanceofClassUnion($node): ?Op\Type\Union_
    {
        if ($node instanceof Node\UnionType) {
            return $this->parseTypeNode($node);
        }
        $names = $this->flattenInstanceofBitwiseOrUnion($node);
        if (null === $names || count($names) < 2) {
            return null;
        }
        $types = [];
        foreach ($names as $name) {
            $types[] = new Op\Type\Literal($name, []);
        }

        return new Op\Type\Union_($types, []);
    }

    /**
     * @return null|list<string>
     */
    private function flattenInstanceofBitwiseOrUnion($node): ?array
    {
        if ($node instanceof Node\UnionType) {
            $union = $this->parseTypeNode($node);
            if (!$union instanceof Op\Type\Union_) {
                return null;
            }
            $names = [];
            foreach ($union->types as $type) {
                if (!$type instanceof Op\Type\Literal) {
                    return null;
                }
                $names[] = $type->name;
            }

            return $names;
        }
        if ($node instanceof Node\Expr\BinaryOp\BitwiseOr) {
            $left = $this->flattenInstanceofBitwiseOrUnion($node->left);
            $right = $this->flattenInstanceofBitwiseOrUnion($node->right);
            if (null === $left || null === $right) {
                return null;
            }

            return array_merge($left, $right);
        }
        $name = $this->instanceofClassNameFromNode($node);

        return null !== $name ? [$name] : null;
    }

    private function instanceofClassNameFromNode($node): ?string
    {
        if ($node instanceof Node\Name) {
            return $node->toString();
        }
        if ($node instanceof Node\Expr\ConstFetch && $node->name instanceof Node\Name) {
            return $node->name->toString();
        }

        return null;
    }

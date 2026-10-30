<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocNodeFinder;

use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationArguments;
use Rector\PhpDocParser\PhpDocParser\PhpDocNodeVisitor\CallablePhpDocNodeVisitor;

final class PhpDocNodeByTypeFinder
{
    /**
     * @template TNode as \PHPStan\PhpDocParser\Ast\Node
     * @param class-string<TNode> $desiredType
     * @return array<TNode>
     */
    public function findByType(PhpDocNode $phpDocNode, string $desiredType): array
    {
        $foundNodes = [];

        $phpDocNodeTraverser = new NodeTraverser([new CallablePhpDocNodeVisitor(static function (Node $node) use (
            &$foundNodes,
            $desiredType
        ): Node {
            if (! $node instanceof $desiredType) {
                return $node;
            }

            /** @var TNode $node */
            $foundNodes[] = $node;
            return $node;
        })]);
        $phpDocNodeTraverser->traverse([$phpDocNode]);

        return $foundNodes;
    }

    /**
     * @param string[] $classes
     * @return DoctrineAnnotation[]
     */
    public function findDoctrineAnnotationsByClasses(PhpDocNode $phpDocNode, array $classes): array
    {
        $doctrineAnnotations = [];

        foreach ($classes as $class) {
            $doctrineAnnotations = [
                ...$doctrineAnnotations,
                ...$this->findDoctrineAnnotationsByClass($phpDocNode, $class),
            ];
        }

        return $doctrineAnnotations;
    }

    /**
     * @param class-string $desiredClass
     * @return DoctrineAnnotation[]
     */
    public function findDoctrineAnnotationsByClass(PhpDocNode $phpDocNode, string $desiredClass): array
    {
        $desiredDoctrineAnnotations = [];

        $doctrineAnnotations = $this->findByType($phpDocNode, DoctrineAnnotation::class);
        foreach ($doctrineAnnotations as $doctrineAnnotation) {
            if (DoctrineAnnotationArguments::hasClassName($doctrineAnnotation, $desiredClass)) {
                $desiredDoctrineAnnotations[] = $doctrineAnnotation;
            }
        }

        return $desiredDoctrineAnnotations;
    }
}

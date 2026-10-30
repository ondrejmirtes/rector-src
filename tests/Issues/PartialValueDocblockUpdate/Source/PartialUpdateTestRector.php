<?php

declare(strict_types=1);

namespace Rector\Tests\Issues\PartialValueDocblockUpdate\Source;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArray;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationArguments;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\RuleDefinition;

final class PartialUpdateTestRector extends AbstractRector
{
    public function __construct(
        private readonly DocBlockUpdater $docBlockUpdater,
        private readonly PhpDocInfoFactory $phpDocInfoFactory
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('uff', []);
    }

    public function getNodeTypes(): array
    {
        return [
            ClassMethod::class,
        ];
    }

    public function refactor(Node $node)
    {
        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);
        $routeDoctrineAnnotation = $phpDocInfo->getByAnnotationClass('Symfony\Component\Routing\Annotation\Route');

        if (! $routeDoctrineAnnotation instanceof DoctrineAnnotation) {
            return null;
        }

        $defaultsDoctrineArgument = DoctrineAnnotationArguments::get($routeDoctrineAnnotation, 'defaults');
        if (! $defaultsDoctrineArgument instanceof DoctrineArgument) {
            $routeDoctrineAnnotation->arguments[] = new DoctrineArgument(
                new IdentifierTypeNode('defaults'),
                new DoctrineArray([]),
            );

            $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($node);

            return $node;
        }

        return null;
    }
}

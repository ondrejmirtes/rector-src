<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocManipulator;

use Nette\Utils\Strings;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArray;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationArguments;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocParser\ClassAnnotationMatcher;
use Rector\Enum\ClassName;
use Rector\Renaming\Collector\RenamedNameCollector;

final class PhpDocClassRenamer
{
    /**
     * @var array<string, string>
     */
    private array $wordBoundaryPatternByOldClass = [];

    public function __construct(
        private readonly ClassAnnotationMatcher $classAnnotationMatcher,
        private readonly RenamedNameCollector $renamedNameCollector
    ) {
    }

    /**
     * Covers annotations like @ORM, @Serializer, @Assert etc
     * See https://github.com/rectorphp/rector/issues/1872
     *
     * @param string[] $oldToNewClasses
     */
    public function changeTypeInAnnotationTypes(
        Node $node,
        PhpDocInfo $phpDocInfo,
        array $oldToNewClasses,
        bool &$hasChanged
    ): bool {
        $this->processAssertChoiceTagValueNode($oldToNewClasses, $phpDocInfo, $hasChanged);
        $this->processDoctrineRelationTagValueNode($node, $oldToNewClasses, $phpDocInfo, $hasChanged);
        $this->processSerializerTypeTagValueNode($oldToNewClasses, $phpDocInfo, $hasChanged);

        return $hasChanged;
    }

    /**
     * @param array<string, string> $oldToNewClasses
     */
    private function processAssertChoiceTagValueNode(
        array $oldToNewClasses,
        PhpDocInfo $phpDocInfo,
        bool &$hasChanged
    ): void {
        $assertChoiceDoctrineAnnotation = $phpDocInfo->findOneByAnnotationClass(
            'Symfony\Component\Validator\Constraints\Choice'
        );
        if (! $assertChoiceDoctrineAnnotation instanceof DoctrineAnnotation) {
            return;
        }

        $callbackDoctrineArgument = DoctrineAnnotationArguments::get($assertChoiceDoctrineAnnotation, 'callback');

        // array is needed for callable
        if (! $callbackDoctrineArgument instanceof DoctrineArgument || ! $callbackDoctrineArgument->value instanceof DoctrineArray) {
            return;
        }

        $classNameStringNode = $callbackDoctrineArgument->value->items[0]->value ?? null;
        if (! $classNameStringNode instanceof ConstExprStringNode) {
            return;
        }

        foreach ($oldToNewClasses as $oldClass => $newClass) {
            if ($classNameStringNode->value !== $oldClass) {
                continue;
            }

            $this->renamedNameCollector->add($oldClass);
            $classNameStringNode->value = $newClass;
            $hasChanged = true;
            break;
        }
    }

    /**
     * @param array<string, string> $oldToNewClasses
     */
    private function processDoctrineRelationTagValueNode(
        Node $node,
        array $oldToNewClasses,
        PhpDocInfo $phpDocInfo,
        bool &$hasChanged
    ): void {
        $doctrineAnnotation = $phpDocInfo->getByAnnotationClasses([
            'Doctrine\ORM\Mapping\OneToMany',
            'Doctrine\ORM\Mapping\ManyToMany',
            'Doctrine\ORM\Mapping\Embedded',
        ]);

        if (! $doctrineAnnotation instanceof DoctrineAnnotation) {
            return;
        }

        $this->processDoctrineToMany($doctrineAnnotation, $node, $oldToNewClasses, $hasChanged);
    }

    /**
     * @param array<string, string> $oldToNewClasses
     */
    private function processSerializerTypeTagValueNode(
        array $oldToNewClasses,
        PhpDocInfo $phpDocInfo,
        bool &$hasChanged
    ): void {
        $doctrineAnnotation = $phpDocInfo->findOneByAnnotationClass(ClassName::JMS_TYPE);
        if (! $doctrineAnnotation instanceof DoctrineAnnotation) {
            return;
        }

        $classNameStringNode = DoctrineAnnotationArguments::getSilent($doctrineAnnotation)?->value;

        // loop-invariant, resolve once instead of per configured class
        $currentTypeStringNode = DoctrineAnnotationArguments::get($doctrineAnnotation, 'type')?->value;

        foreach ($oldToNewClasses as $oldClass => $newClass) {
            if ($classNameStringNode instanceof ConstExprStringNode) {
                if ($classNameStringNode->value === $oldClass) {
                    $classNameStringNode->value = $newClass;
                    continue;
                }

                $this->renamedNameCollector->add($oldClass);
                $classNameStringNode->value = Strings::replace(
                    $classNameStringNode->value,
                    $this->wordBoundaryPatternByOldClass[$oldClass] ??= '#\b' . preg_quote($oldClass, '#') . '\b#',
                    $newClass
                );

                $hasChanged = true;
            }

            if ($currentTypeStringNode instanceof ConstExprStringNode && $currentTypeStringNode->value === $oldClass) {
                $currentTypeStringNode->value = $newClass;

                $hasChanged = true;
            }
        }
    }

    /**
     * @param array<string, string> $oldToNewClasses
     */
    private function processDoctrineToMany(
        DoctrineAnnotation $doctrineAnnotation,
        Node $node,
        array $oldToNewClasses,
        bool &$hasChanged
    ): void {
        $classKey = DoctrineAnnotationArguments::hasClassName(
            $doctrineAnnotation,
            'Doctrine\ORM\Mapping\Embedded'
        ) ? 'class' : 'targetEntity';

        $targetEntityStringNode = DoctrineAnnotationArguments::get($doctrineAnnotation, $classKey)?->value;
        if (! $targetEntityStringNode instanceof ConstExprStringNode) {
            return;
        }

        // resolve to FQN
        $tagFullyQualifiedName = $this->classAnnotationMatcher->resolveTagFullyQualifiedName(
            $targetEntityStringNode->value,
            $node
        );

        foreach ($oldToNewClasses as $oldClass => $newClass) {
            if ($tagFullyQualifiedName !== $oldClass) {
                continue;
            }

            $this->renamedNameCollector->add($oldClass);
            $targetEntityStringNode->value = $newClass;

            $hasChanged = true;
        }
    }
}

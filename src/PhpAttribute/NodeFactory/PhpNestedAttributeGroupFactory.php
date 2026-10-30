<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\NodeFactory;

use Nette\Utils\Strings;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Use_;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArray;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationArguments;
use Rector\Exception\ShouldNotHappenException;
use Rector\Php80\ValueObject\AnnotationPropertyToAttributeClass;
use Rector\Php80\ValueObject\NestedAnnotationToAttribute;
use Rector\PhpAttribute\AnnotationToAttributeMapper;
use Rector\PhpAttribute\AttributeArrayNameInliner;
use Webmozart\Assert\Assert;

final readonly class PhpNestedAttributeGroupFactory
{
    public function __construct(
        private AnnotationToAttributeMapper $annotationToAttributeMapper,
        private AttributeNameFactory $attributeNameFactory,
        private NamedArgsFactory $namedArgsFactory,
        private AttributeArrayNameInliner $attributeArrayNameInliner,
    ) {
    }

    /**
     * @param Use_[] $uses
     */
    public function create(
        DoctrineAnnotation $doctrineAnnotation,
        NestedAnnotationToAttribute $nestedAnnotationToAttribute,
        array $uses
    ): AttributeGroup {
        $values = $this->removeItems($doctrineAnnotation->arguments, $nestedAnnotationToAttribute);

        $args = $this->createArgsFromItems($values);

        $args = $this->attributeArrayNameInliner->inlineArrayToArgs($args);

        $attributeName = $this->attributeNameFactory->create(
            $nestedAnnotationToAttribute,
            $doctrineAnnotation,
            $uses
        );

        $attribute = new Attribute($attributeName, $args);
        return new AttributeGroup([$attribute]);
    }

    /**
     * @return AttributeGroup[]
     */
    public function createNested(
        DoctrineAnnotation $doctrineAnnotation,
        NestedAnnotationToAttribute $nestedAnnotationToAttribute,
    ): array {
        $attributeGroups = [];

        if ($nestedAnnotationToAttribute->hasExplicitParameters()) {
            return $this->createFromExplicitProperties(
                $nestedAnnotationToAttribute,
                $doctrineAnnotation
            );
        }

        $nestedAnnotationPropertyToAttributeClass = $nestedAnnotationToAttribute->getAnnotationPropertiesToAttributeClasses()[0];

        foreach ($doctrineAnnotation->arguments as $doctrineArgument) {
            if (! $doctrineArgument->value instanceof DoctrineArray) {
                continue;
            }

            foreach ($doctrineArgument->value->items as $doctrineArrayItem) {
                if (! $doctrineArrayItem->value instanceof DoctrineAnnotation) {
                    continue;
                }

                $attributeArgs = $this->createAttributeArgs($doctrineArrayItem->value);

                $originalIdentifier = $doctrineAnnotation->name;

                $attributeName = $this->resolveAliasedAttributeName(
                    $originalIdentifier,
                    $nestedAnnotationPropertyToAttributeClass
                );

                $attribute = new Attribute($attributeName, $attributeArgs);
                $attributeGroups[] = new AttributeGroup([$attribute]);
            }
        }

        return $attributeGroups;
    }

    /**
     * @return list<Arg>
     */
    private function createAttributeArgs(DoctrineAnnotation $nestedDoctrineAnnotation): array
    {
        $args = $this->createArgsFromItems($nestedDoctrineAnnotation->arguments);

        return $this->attributeArrayNameInliner->inlineArrayToArgs($args);
    }

    /**
     * @param DoctrineArgument[] $doctrineArguments
     * @return list<Arg>
     */
    private function createArgsFromItems(array $doctrineArguments): array
    {
        $mappedItems = $this->annotationToAttributeMapper->map($doctrineArguments);

        $values = $mappedItems instanceof Array_ ? $mappedItems->items : $mappedItems;

        return $this->namedArgsFactory->createFromValues($values);
    }

    /**
     * @todo improve this hardcoded approach later
     */
    private function resolveAliasedAttributeName(
        string $originalIdentifier,
        AnnotationPropertyToAttributeClass $annotationPropertyToAttributeClass
    ): FullyQualified|Name {
        /** @var string $shortDoctrineAttributeName */
        $shortDoctrineAttributeName = Strings::after(
            $annotationPropertyToAttributeClass->getAttributeClass(),
            '\\',
            -1
        );

        if (str_starts_with($originalIdentifier, '@ORM')) {
            // or alias
            return new Name('ORM\\' . $shortDoctrineAttributeName);
        }

        // short alias
        if (! str_contains($originalIdentifier, '\\')) {
            return new Name($shortDoctrineAttributeName);
        }

        return new FullyQualified($annotationPropertyToAttributeClass->getAttributeClass());
    }

    /**
     * @param DoctrineArgument[] $doctrineArguments
     * @return DoctrineArgument[]
     */
    private function removeItems(
        array $doctrineArguments,
        NestedAnnotationToAttribute $nestedAnnotationToAttribute
    ): array {
        foreach ($nestedAnnotationToAttribute->getAnnotationPropertiesToAttributeClasses() as $annotationPropertyToAttributeClass) {
            foreach ($doctrineArguments as $key => $doctrineArgument) {
                if ($doctrineArgument->key?->name !== $annotationPropertyToAttributeClass->getAnnotationProperty()) {
                    continue;
                }

                unset($doctrineArguments[$key]);
            }
        }

        return $doctrineArguments;
    }

    /**
     * @return AttributeGroup[]
     */
    private function createFromExplicitProperties(
        NestedAnnotationToAttribute $nestedAnnotationToAttribute,
        DoctrineAnnotation $doctrineAnnotation
    ): array {
        $attributeGroups = [];

        foreach ($nestedAnnotationToAttribute->getAnnotationPropertiesToAttributeClasses() as $annotationPropertyToAttributeClass) {
            /** @var string $annotationProperty */
            $annotationProperty = $annotationPropertyToAttributeClass->getAnnotationProperty();

            $nestedDoctrineArgument = DoctrineAnnotationArguments::get($doctrineAnnotation, $annotationProperty);
            if (! $nestedDoctrineArgument instanceof DoctrineArgument) {
                continue;
            }

            if (! $nestedDoctrineArgument->value instanceof DoctrineArray) {
                throw new ShouldNotHappenException();
            }

            foreach ($nestedDoctrineArgument->value->items as $doctrineArrayItem) {
                $nestedDoctrineAnnotation = $doctrineArrayItem->value;

                Assert::isInstanceOf($nestedDoctrineAnnotation, DoctrineAnnotation::class);

                $attributeArgs = $this->createAttributeArgs($nestedDoctrineAnnotation);

                $originalIdentifier = $nestedDoctrineAnnotation->name;

                $attributeName = $this->resolveAliasedAttributeName(
                    $originalIdentifier,
                    $annotationPropertyToAttributeClass
                );

                if ($annotationPropertyToAttributeClass->doesNeedNewImport() && count(
                    $attributeName->getParts()
                ) === 1) {
                    $attributeName = new FullyQualified($annotationPropertyToAttributeClass->getAttributeClass());
                }

                $attribute = new Attribute($attributeName, $attributeArgs);
                $attributeGroups[] = new AttributeGroup([$attribute]);
            }
        }

        return $attributeGroups;
    }
}

<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\NodeFactory;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Rector\BetterPhpDocParser\ValueObject\DoctrineAnnotation\SilentKeyMap;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\Php81\Enum\AttributeName;
use Rector\PhpAttribute\AnnotationToAttributeMapper;
use Rector\PhpAttribute\AttributeArrayNameInliner;

/**
 * @see \Rector\Tests\PhpAttribute\Printer\PhpAttributeGroupFactoryTest
 */
final readonly class PhpAttributeGroupFactory
{
    public function __construct(
        private AnnotationToAttributeMapper $annotationToAttributeMapper,
        private AttributeNameFactory $attributeNameFactory,
        private NamedArgsFactory $namedArgsFactory,
        private AnnotationToAttributeIntegerValueCaster $annotationToAttributeIntegerValueCaster,
        private AttributeArrayNameInliner $attributeArrayNameInliner
    ) {
    }

    public function createFromSimpleTag(
        AnnotationToAttribute $annotationToAttribute,
        ?string $value = null
    ): AttributeGroup {
        return $this->createFromClass($annotationToAttribute->getAttributeClass(), $value);
    }

    /**
     * @param AttributeName::*|string $attributeClass
     */
    public function createFromClass(string $attributeClass, ?string $value = null): AttributeGroup
    {
        $fullyQualified = new FullyQualified($attributeClass);
        $attribute = new Attribute($fullyQualified);
        if ($value !== null && $value !== '') {
            $arg = new Arg(new String_($value));
            $attribute->args = [$arg];
        }

        return new AttributeGroup([$attribute]);
    }

    /**
     * @param mixed[] $items
     */
    public function createFromClassWithItems(string $attributeClass, array $items): AttributeGroup
    {
        $fullyQualified = new FullyQualified($attributeClass);

        $args = $this->createArgsFromItems($items);
        $attribute = new Attribute($fullyQualified, $args);

        return new AttributeGroup([$attribute]);
    }

    /**
     * @param Use_[] $uses
     */
    public function create(
        DoctrineAnnotation $doctrineAnnotation,
        AnnotationToAttribute $annotationToAttribute,
        array $uses
    ): AttributeGroup {
        $values = $this->resolveArgumentsWithSilentKey($doctrineAnnotation);
        $args = $this->createArgsFromItems($values, '', $annotationToAttribute->getClassReferenceFields());

        $this->annotationToAttributeIntegerValueCaster->castAttributeTypes($annotationToAttribute, $args);

        $args = $this->attributeArrayNameInliner->inlineArrayToArgs($args, $annotationToAttribute->getAttributeClass());

        $attributeName = $this->attributeNameFactory->create(
            $annotationToAttribute,
            $doctrineAnnotation,
            $uses
        );

        // keep FQN in the attribute, so it can be easily detected later
        $attributeName->setAttribute(AttributeKey::PHP_ATTRIBUTE_NAME, $annotationToAttribute->getAttributeClass());

        $attribute = new Attribute($attributeName, $args);
        return new AttributeGroup([$attribute]);
    }

    /**
     * @param DoctrineArgument[]|mixed[] $items
     * @param string $attributeClass @deprecated
     * @param string[] $classReferencedFields
     *
     * @return list<Arg>
     */
    public function createArgsFromItems(
        array $items,
        string $attributeClass = '',
        array $classReferencedFields = []
    ): array {
        $mappedItems = $this->annotationToAttributeMapper->map($items);

        $this->mapClassReferences($mappedItems, $classReferencedFields);

        $values = $mappedItems instanceof Array_ ? $mappedItems->items : $mappedItems;

        // the key here should contain the named argument
        return $this->namedArgsFactory->createFromValues($values);
    }

    /**
     * The first argument without a key gets the name of the attribute parameter, e.g. "path" for @Route("/home")
     *
     * @return DoctrineArgument[]
     */
    private function resolveArgumentsWithSilentKey(DoctrineAnnotation $doctrineAnnotation): array
    {
        $resolvedClass = $doctrineAnnotation->getAttribute(PhpDocAttributeKey::RESOLVED_CLASS);
        $silentKey = SilentKeyMap::CLASS_NAMES_TO_SILENT_KEYS[$resolvedClass] ?? null;
        if ($silentKey === null) {
            return $doctrineAnnotation->arguments;
        }

        $doctrineArguments = $doctrineAnnotation->arguments;
        foreach ($doctrineArguments as $key => $doctrineArgument) {
            if ($doctrineArgument->key === null) {
                $doctrineArguments[$key] = new DoctrineArgument(new IdentifierTypeNode($silentKey), $doctrineArgument->value);
                break;
            }
        }

        return $doctrineArguments;
    }

    /**
     * @param string[] $classReferencedFields
     */
    private function mapClassReferences(Expr|string $expr, array $classReferencedFields): void
    {
        if (! $expr instanceof Array_) {
            return;
        }

        foreach ($expr->items as $arrayItem) {
            if (! $arrayItem instanceof ArrayItem) {
                continue;
            }

            if (! $arrayItem->key instanceof String_) {
                continue;
            }

            if (! in_array($arrayItem->key->value, $classReferencedFields)) {
                continue;
            }

            if ($arrayItem->value instanceof ClassConstFetch) {
                continue;
            }

            if (! $arrayItem->value instanceof String_) {
                continue;
            }

            if ($arrayItem->value->value === '') {
                continue;
            }

            $arrayItem->value = new ClassConstFetch(new FullyQualified($arrayItem->value->value), 'class');
        }
    }
}

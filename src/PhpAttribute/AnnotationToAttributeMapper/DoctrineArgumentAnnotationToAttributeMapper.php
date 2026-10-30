<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\AnnotationToAttributeMapper;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArrayItem;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Rector\PhpAttribute\AnnotationToAttributeMapper;
use Rector\PhpAttribute\Contract\AnnotationToAttributeMapperInterface;
use Rector\PhpAttribute\Enum\DocTagNodeState;

/**
 * @implements AnnotationToAttributeMapperInterface<DoctrineArgument|DoctrineArrayItem>
 */
final class DoctrineArgumentAnnotationToAttributeMapper implements AnnotationToAttributeMapperInterface
{
    private AnnotationToAttributeMapper $annotationToAttributeMapper;

    /**
     * Avoid circular reference
     */
    public function autowire(AnnotationToAttributeMapper $annotationToAttributeMapper): void
    {
        $this->annotationToAttributeMapper = $annotationToAttributeMapper;
    }

    public function isCandidate(mixed $value): bool
    {
        return $value instanceof DoctrineArgument || $value instanceof DoctrineArrayItem;
    }

    /**
     * @param DoctrineArgument|DoctrineArrayItem $value
     */
    public function map($value): ArrayItem
    {
        $valueExpr = $this->annotationToAttributeMapper->map($value->value);

        if ($valueExpr === DocTagNodeState::REMOVE_ARRAY) {
            return new ArrayItem(new String_($valueExpr));
        }

        $key = $value->key;
        if ($key === null) {
            return new ArrayItem($valueExpr);
        }

        // a key is a plain name, never a constant
        /** @var Expr $keyExpr */
        $keyExpr = $this->annotationToAttributeMapper->map(
            $key instanceof IdentifierTypeNode ? $key->name : $key
        );

        return new ArrayItem($valueExpr, $keyExpr);
    }
}

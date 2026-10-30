<?php

declare(strict_types=1);

namespace Rector\PhpAttribute;

use PhpParser\BuilderHelpers;
use PhpParser\Node\Expr;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Rector\PhpAttribute\Contract\AnnotationToAttributeMapperInterface;
use Rector\PhpAttribute\Enum\DocTagNodeState;
use Webmozart\Assert\Assert;

/**
 * @see \Rector\Tests\PhpAttribute\AnnotationToAttributeMapper\AnnotationToAttributeMapperTest
 */
final readonly class AnnotationToAttributeMapper
{
    /**
     * @param AnnotationToAttributeMapperInterface[] $annotationToAttributeMappers
     */
    public function __construct(
        private array $annotationToAttributeMappers
    ) {
        Assert::notEmpty($annotationToAttributeMappers);
    }

    public function map(mixed $value): mixed
    {
        // a constant or a bare word, e.g. true in @Column(nullable=true)
        if ($value instanceof IdentifierTypeNode) {
            $value = match (strtolower($value->name)) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $value->name,
            };
        }

        foreach ($this->annotationToAttributeMappers as $annotationToAttributeMapper) {
            if ($annotationToAttributeMapper->isCandidate($value)) {
                return $annotationToAttributeMapper->map($value);
            }
        }

        if ($value instanceof Expr) {
            return $value;
        }

        // remove node, as handled elsewhere
        if ($value instanceof DoctrineAnnotation) {
            return DocTagNodeState::REMOVE_ARRAY;
        }

        // fallback
        return BuilderHelpers::normalizeValue($value);
    }
}

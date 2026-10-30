<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\AnnotationToAttributeMapper;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use Rector\Exception\ShouldNotHappenException;
use Rector\Php\PhpVersionProvider;
use Rector\PhpAttribute\AnnotationToAttributeMapper;
use Rector\PhpAttribute\AttributeArrayNameInliner;
use Rector\PhpAttribute\Contract\AnnotationToAttributeMapperInterface;
use Rector\ValueObject\PhpVersion;

/**
 * @implements AnnotationToAttributeMapperInterface<DoctrineAnnotation>
 */
final class DoctrineAnnotationAnnotationToAttributeMapper implements AnnotationToAttributeMapperInterface
{
    private AnnotationToAttributeMapper $annotationToAttributeMapper;

    public function __construct(
        private readonly PhpVersionProvider $phpVersionProvider,
        private readonly AttributeArrayNameInliner $attributeArrayNameInliner,
    ) {
    }

    /**
     * Avoid circular reference
     */
    public function autowire(AnnotationToAttributeMapper $annotationToAttributeMapper): void
    {
        $this->annotationToAttributeMapper = $annotationToAttributeMapper;
    }

    public function isCandidate(mixed $value): bool
    {
        if (! $value instanceof DoctrineAnnotation) {
            return false;
        }

        return $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersion::PHP_81);
    }

    /**
     * @param DoctrineAnnotation $value
     */
    public function map($value): New_
    {
        $annotationShortName = ltrim($value->name, '@');
        if ($value->arguments !== []) {
            $argValues = $this->annotationToAttributeMapper->map($value->arguments);

            if ($argValues instanceof Array_) {
                // create named args
                $args = $this->attributeArrayNameInliner->inlineArrayToArgs($argValues);
            } else {
                throw new ShouldNotHappenException();
            }
        } else {
            $args = [];
        }

        return new New_(new Name($annotationShortName), $args);
    }
}

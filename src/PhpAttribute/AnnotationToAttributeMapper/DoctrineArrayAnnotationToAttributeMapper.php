<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\AnnotationToAttributeMapper;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArray;
use Rector\PhpAttribute\AnnotationToAttributeMapper;
use Rector\PhpAttribute\Contract\AnnotationToAttributeMapperInterface;
use Webmozart\Assert\Assert;

/**
 * @implements AnnotationToAttributeMapperInterface<DoctrineArray>
 */
final class DoctrineArrayAnnotationToAttributeMapper implements AnnotationToAttributeMapperInterface
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
        return $value instanceof DoctrineArray;
    }

    /**
     * @param DoctrineArray $value
     */
    public function map($value): Array_
    {
        $arrayItems = [];
        foreach ($value->items as $doctrineArrayItem) {
            $arrayItem = $this->annotationToAttributeMapper->map($doctrineArrayItem);
            Assert::isInstanceOf($arrayItem, ArrayItem::class);

            $arrayItems[] = $arrayItem;
        }

        return new Array_($arrayItems);
    }
}

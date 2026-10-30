<?php

declare(strict_types=1);

namespace Rector\Php80\ValueObject;

use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;

final readonly class NestedDoctrineTagAndAnnotationToAttribute
{
    public function __construct(
        private DoctrineAnnotation $doctrineAnnotation,
        private NestedAnnotationToAttribute $nestedAnnotationToAttribute,
    ) {
    }

    public function getDoctrineAnnotation(): DoctrineAnnotation
    {
        return $this->doctrineAnnotation;
    }

    public function getNestedAnnotationToAttribute(): NestedAnnotationToAttribute
    {
        return $this->nestedAnnotationToAttribute;
    }
}

<?php

declare(strict_types=1);

namespace Rector\Php80\ValueObject;

use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;

final readonly class DoctrineTagAndAnnotationToAttribute
{
    public function __construct(
        private DoctrineAnnotation $doctrineAnnotation,
        private AnnotationToAttribute $annotationToAttribute,
    ) {
    }

    public function getDoctrineAnnotation(): DoctrineAnnotation
    {
        return $this->doctrineAnnotation;
    }

    public function getAnnotationToAttribute(): AnnotationToAttribute
    {
        return $this->annotationToAttribute;
    }
}

<?php

declare(strict_types=1);

namespace Rector\Php80\Rector\Class_;

use Nette\Utils\Strings;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\Php80\ValueObject\AttributeValueAndDocComment;
use Rector\Util\NewLineSplitter;

final class AttributeValueResolver
{
    /**
     * @see https://regex101.com/r/CL9ktz/4
     */
    private const string END_SLASH_REGEX = '#\\\\$#';

    public function resolve(
        AnnotationToAttribute $annotationToAttribute,
        PhpDocTagNode $phpDocTagNode
    ): ?AttributeValueAndDocComment {
        if (! $annotationToAttribute->getUseValueAsAttributeArgument()) {
            return null;
        }

        $docValue = (string) $phpDocTagNode->value;

        if ($phpDocTagNode->value instanceof DoctrineTagValueNode) {
            $doctrineTagValueNode = $phpDocTagNode->value;
            if ($doctrineTagValueNode->annotation->arguments === []) {
                $docValue = $doctrineTagValueNode->description;
            } else {
                $docValue = '(' . implode(', ', $doctrineTagValueNode->annotation->arguments) . ')';
                if ($doctrineTagValueNode->description !== '') {
                    $docValue .= "\n" . $doctrineTagValueNode->description;
                }
            }
        }

        $docComment = '';

        // special case for newline
        if (str_contains($docValue, "\n")) {
            $keepJoining = true;
            $docValueLines = NewLineSplitter::split($docValue);

            $joinDocValue = '';

            $hasPreviousEndSlash = false;

            foreach ($docValueLines as $key => $docValueLine) {
                if ($keepJoining) {
                    $joinDocValue .= rtrim($docValueLine, '\\\\');
                }

                if (Strings::match($docValueLine, self::END_SLASH_REGEX) === null) {
                    if ($hasPreviousEndSlash === false && $key > 0) {
                        if ($docComment === '') {
                            $docComment .= $docValueLine;
                        } else {
                            $docComment .= "\n" . $docValueLine;
                        }
                    }

                    $keepJoining = false;
                } else {
                    $hasPreviousEndSlash = true;
                }
            }

            $docValue = $joinDocValue;
        }

        return new AttributeValueAndDocComment($docValue, $docComment);
    }
}

<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDoc;

use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;

/**
 * Lookups on the native phpdoc-parser Doctrine annotation node
 */
final class DoctrineAnnotationArguments
{
    public static function hasClassName(DoctrineAnnotation $doctrineAnnotation, string $className): bool
    {
        if (ltrim($doctrineAnnotation->name, '@') === $className) {
            return true;
        }

        // the name is not fully qualified in the original name, look for resolved class attribute
        return $doctrineAnnotation->getAttribute(PhpDocAttributeKey::RESOLVED_CLASS) === $className;
    }

    public static function get(DoctrineAnnotation $doctrineAnnotation, string $key): ?DoctrineArgument
    {
        foreach ($doctrineAnnotation->arguments as $doctrineArgument) {
            if ($doctrineArgument->key?->name === $key) {
                return $doctrineArgument;
            }
        }

        return null;
    }

    /**
     * The first argument without a key, e.g. "/path" in @Route("/path", name="home")
     */
    public static function getSilent(DoctrineAnnotation $doctrineAnnotation): ?DoctrineArgument
    {
        foreach ($doctrineAnnotation->arguments as $doctrineArgument) {
            if ($doctrineArgument->key === null) {
                return $doctrineArgument;
            }
        }

        return null;
    }

    /**
     * @api
     */
    public static function remove(DoctrineAnnotation $doctrineAnnotation, string $key): void
    {
        $doctrineAnnotation->arguments = array_values(array_filter(
            $doctrineAnnotation->arguments,
            static fn (DoctrineArgument $doctrineArgument): bool => $doctrineArgument->key?->name !== $key
        ));
    }
}

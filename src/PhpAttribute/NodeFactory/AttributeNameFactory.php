<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\NodeFactory;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Use_;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use Rector\Php80\Contract\ValueObject\AnnotationToAttributeInterface;
use Rector\PhpAttribute\UseAliasNameMatcher;
use Rector\PhpAttribute\ValueObject\UseAliasMetadata;

final readonly class AttributeNameFactory
{
    public function __construct(
        private UseAliasNameMatcher $useAliasNameMatcher
    ) {
    }

    /**
     * @param Use_[] $uses
     */
    public function create(
        AnnotationToAttributeInterface $annotationToAttribute,
        DoctrineAnnotation $doctrineAnnotation,
        array $uses
    ): FullyQualified|Name {
        // A. attribute and class name are the same, so we re-use the short form to keep code compatible with previous one,
        // except start with \
        if ($annotationToAttribute->getAttributeClass() === $annotationToAttribute->getTag()) {
            $attributeName = $doctrineAnnotation->name;
            $attributeName = ltrim($attributeName, '@');

            if (str_starts_with($attributeName, '\\')) {
                return new FullyQualified(ltrim($attributeName, '\\'));
            }

            return new Name($attributeName);
        }

        // B. different name
        $useAliasMetadata = $this->useAliasNameMatcher->match(
            $uses,
            $doctrineAnnotation->name,
            $annotationToAttribute
        );
        if ($useAliasMetadata instanceof UseAliasMetadata) {
            $useUse = $useAliasMetadata->getUseUse();

            // is same as name?
            $useImportName = $useAliasMetadata->getUseImportName();
            if ($useUse->name->toString() !== $useImportName) {
                // no? rename
                $useUse->name = new Name($useImportName);
            }

            return new Name($useAliasMetadata->getShortAttributeName());
        }

        // 3. the class is not aliased and is completely new... return the FQN version
        return new FullyQualified($annotationToAttribute->getAttributeClass());
    }
}

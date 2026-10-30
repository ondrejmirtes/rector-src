<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\NodeDecorator;

use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\Node as PhpDocParserNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\Type\ObjectType;
use Rector\BetterPhpDocParser\Contract\PhpDocParser\PhpDocNodeDecoratorInterface;
use Rector\BetterPhpDocParser\PhpDocInfo\TokenIteratorFactory;
use Rector\BetterPhpDocParser\PhpDocParser\ClassAnnotationMatcher;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpDocParser\PhpDocParser\PhpDocNodeVisitor\CallablePhpDocNodeVisitor;
use Rector\StaticTypeMapper\ValueObject\Type\AliasedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\ShortenedObjectType;
use Rector\TypeDeclaration\PHPStan\ObjectTypeSpecifier;

/**
 * Resolves the classes of Doctrine annotations.
 * A tag without arguments, e.g. @ORM\Id, becomes a Doctrine annotation with no arguments.
 */
final readonly class DoctrineAnnotationDecorator implements PhpDocNodeDecoratorInterface
{
    /**
     * Special short annotations, that are resolved as FQN by Doctrine annotation parser
     * @var string[]
     */
    private const array ALLOWED_SHORT_ANNOTATIONS = ['Target'];

    public function __construct(
        private ClassAnnotationMatcher $classAnnotationMatcher,
        private TokenIteratorFactory $tokenIteratorFactory,
        private ObjectTypeSpecifier $objectTypeSpecifier
    ) {
    }

    public function decorate(PhpDocNode $phpDocNode, Node $phpNode): void
    {
        $incompleteAnnotationContent = null;

        foreach ($phpDocNode->children as $phpDocChildNode) {
            // the next children are the rest of an incomplete annotation, e.g. its nested annotations; keep them
            if ($incompleteAnnotationContent !== null) {
                $incompleteAnnotationContent .= "\n" . $phpDocChildNode;
                if ($this->isClosedContent($incompleteAnnotationContent)) {
                    $incompleteAnnotationContent = null;
                }

                continue;
            }

            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            // the value ends before a nested annotation on the next line
            if ($phpDocChildNode->value instanceof InvalidTagValueNode) {
                if (! $this->isClosedContent($phpDocChildNode->value->value)) {
                    $incompleteAnnotationContent = $phpDocChildNode->value->value;
                }

                continue;
            }

            if ($phpDocChildNode->value instanceof DoctrineTagValueNode) {
                $fullyQualifiedAnnotationClass = $this->resolveDoctrineTagClass($phpDocChildNode->name, $phpNode);
                if ($fullyQualifiedAnnotationClass === null) {
                    continue;
                }

                $doctrineAnnotation = $phpDocChildNode->value->annotation;
                $doctrineAnnotation->setAttribute(PhpDocAttributeKey::RESOLVED_CLASS, $fullyQualifiedAnnotationClass);
                $this->decorateNestedAnnotations($doctrineAnnotation, $phpNode);

                continue;
            }

            if (! $phpDocChildNode->value instanceof GenericTagValueNode) {
                continue;
            }

            // known doc tag to annotation class
            $fullyQualifiedAnnotationClass = $this->classAnnotationMatcher->resolveTagFullyQualifiedName(
                $phpDocChildNode->name,
                $phpNode
            );

            // not an annotations class
            if (! \str_contains($fullyQualifiedAnnotationClass, '\\') && ! in_array(
                $fullyQualifiedAnnotationClass,
                self::ALLOWED_SHORT_ANNOTATIONS,
                true
            )) {
                continue;
            }

            $phpDocChildNode->value = $this->createDoctrineTagValueNode(
                $phpDocChildNode,
                $phpDocChildNode->value,
                $fullyQualifiedAnnotationClass
            );
        }
    }

    /**
     * The annotation has no tokens, so an unchanged annotation is printed without "()"
     */
    private function createDoctrineTagValueNode(
        PhpDocTagNode $phpDocTagNode,
        GenericTagValueNode $genericTagValueNode,
        string $fullyQualifiedAnnotationClass
    ): DoctrineTagValueNode {
        $startIndex = $genericTagValueNode->getAttribute(Attribute::START_INDEX);

        $doctrineAnnotation = new DoctrineAnnotation($phpDocTagNode->name, []);
        $doctrineAnnotation->setAttribute(PhpDocAttributeKey::RESOLVED_CLASS, $fullyQualifiedAnnotationClass);
        $doctrineAnnotation->setAttribute(Attribute::START_INDEX, $startIndex);
        $doctrineAnnotation->setAttribute(Attribute::END_INDEX, $startIndex - 1);

        $doctrineTagValueNode = new DoctrineTagValueNode($doctrineAnnotation, $genericTagValueNode->value);
        foreach ([Attribute::START_LINE, Attribute::END_LINE, Attribute::START_INDEX, Attribute::END_INDEX] as $attributeName) {
            $doctrineTagValueNode->setAttribute($attributeName, $genericTagValueNode->getAttribute($attributeName));
        }

        return $doctrineTagValueNode;
    }

    private function decorateNestedAnnotations(DoctrineAnnotation $doctrineAnnotation, Node $phpNode): void
    {
        $nodeTraverser = new NodeTraverser([new CallablePhpDocNodeVisitor(function (PhpDocParserNode $node) use (
            $phpNode
        ): ?PhpDocParserNode {
            if (! $node instanceof DoctrineAnnotation) {
                return null;
            }

            $node->setAttribute(
                PhpDocAttributeKey::RESOLVED_CLASS,
                $this->classAnnotationMatcher->resolveTagFullyQualifiedName($node->name, $phpNode)
            );

            return $node;
        })]);

        $nodeTraverser->traverse($doctrineAnnotation->arguments);
    }

    private function resolveDoctrineTagClass(string $tagName, Node $phpNode): ?string
    {
        $type = $this->objectTypeSpecifier->narrowToFullyQualifiedOrAliasedObjectType(
            $phpNode,
            new ObjectType(ltrim($tagName, '@')),
            $phpNode->getAttribute(AttributeKey::SCOPE)
        );

        if ($type instanceof ShortenedObjectType || $type instanceof AliasedObjectType) {
            return $type->getFullyQualifiedName();
        }

        if ($type instanceof ObjectType) {
            return $type->getClassName();
        }

        return null;
    }

    /**
     * This is closed block, e.g. {( ... )},
     * false on: {( ... )
     */
    private function isClosedContent(string $composedContent): bool
    {
        $composedTokenIterator = $this->tokenIteratorFactory->create($composedContent);
        $tokenCount = $composedTokenIterator->getTokenCount();

        $openBracketCount = 0;
        $closeBracketCount = 0;
        if ($composedContent === '') {
            return true;
        }

        do {
            if ($composedTokenIterator->isCurrentTokenType(
                Lexer::TOKEN_OPEN_CURLY_BRACKET,
                Lexer::TOKEN_OPEN_PARENTHESES
            ) || \str_contains($composedTokenIterator->currentTokenValue(), '{')
              || \str_contains($composedTokenIterator->currentTokenValue(), '(')
            ) {
                ++$openBracketCount;
            }

            if (
                $composedTokenIterator->isCurrentTokenType(
                    Lexer::TOKEN_CLOSE_CURLY_BRACKET,
                    Lexer::TOKEN_CLOSE_PARENTHESES
                    // sometimes it gets mixed int    ")
                ) || \str_contains($composedTokenIterator->currentTokenValue(), '}')
                  || \str_contains($composedTokenIterator->currentTokenValue(), ')')) {
                ++$closeBracketCount;
            }

            $composedTokenIterator->next();
        } while ($composedTokenIterator->currentTokenIndex() < ($tokenCount - 1));

        return $openBracketCount === $closeBracketCount;
    }
}

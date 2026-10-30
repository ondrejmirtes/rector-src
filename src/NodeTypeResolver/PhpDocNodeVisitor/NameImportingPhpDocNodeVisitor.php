<?php

declare(strict_types=1);

namespace Rector\NodeTypeResolver\PhpDocNodeVisitor;

use Nette\Utils\Strings;
use PhpParser\Node as PhpParserNode;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\FullyQualifiedNameNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use Rector\Application\Provider\CurrentFileProvider;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;
use Rector\CodingStyle\ClassNameImport\ClassNameImportSkipper;
use Rector\Exception\ShouldNotHappenException;
use Rector\PhpParser\Node\FileNode;
use Rector\StaticTypeMapper\PhpDocParser\IdentifierPhpDocTypeMapper;
use Rector\StaticTypeMapper\ValueObject\Type\AliasedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\ShortenedObjectType;
use Rector\ValueObject\Application\File;

final class NameImportingPhpDocNodeVisitor extends AbstractNodeVisitor
{
    private ?PhpParserNode $currentPhpParserNode = null;

    private bool $hasChanged = false;

    public function __construct(
        private readonly ClassNameImportSkipper $classNameImportSkipper,
        private readonly CurrentFileProvider $currentFileProvider,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly IdentifierPhpDocTypeMapper $identifierPhpDocTypeMapper
    ) {
    }

    public function beforeTraverse(array $nodes): ?array
    {
        if (! $this->currentPhpParserNode instanceof PhpParserNode) {
            throw new ShouldNotHappenException('Set "$currentPhpParserNode" first');
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof PhpDocTagNode && $node->value instanceof DoctrineTagValueNode) {
            return $this->enterDoctrineTagNode($node, $node->value->annotation);
        }

        // nested annotation, e.g. @ORM\JoinColumn in @ORM\JoinTable(joinColumns={@ORM\JoinColumn})
        if ($node instanceof DoctrineAnnotation) {
            if (! $node->getAttribute(PhpDocAttributeKey::PARENT) instanceof DoctrineTagValueNode) {
                $this->processNestedDoctrineAnnotation($node);
            }

            return $node;
        }

        // e.g. from Type::toPhpDocNode(); import it the same way as a written \Foo\Bar
        if ($node instanceof FullyQualifiedNameNode) {
            $fullyQualifiedNameNode = $node;
            $node = new IdentifierTypeNode($fullyQualifiedNameNode->toCodeString());
            $node->setAttribute(
                PhpDocAttributeKey::PARENT,
                $fullyQualifiedNameNode->getAttribute(PhpDocAttributeKey::PARENT)
            );
        }

        if (! $node instanceof IdentifierTypeNode) {
            return null;
        }

        if (! $this->currentPhpParserNode instanceof PhpParserNode) {
            throw new ShouldNotHappenException();
        }

        // no \, skip early
        if (! str_contains($node->name, '\\')) {
            return null;
        }

        $staticType = $this->identifierPhpDocTypeMapper->mapIdentifierTypeNode($node, $this->currentPhpParserNode);
        $staticType = $this->resolveFullyQualified($staticType);
        if (! $staticType instanceof FullyQualifiedObjectType) {
            return null;
        }

        $file = $this->currentFileProvider->getFile();
        if (! $file instanceof File) {
            return null;
        }

        return $this->processFqnNameImport($this->currentPhpParserNode, $node, $staticType, $file);
    }

    public function setCurrentNode(PhpParserNode $phpParserNode): void
    {
        $this->hasChanged = false;
        $this->currentPhpParserNode = $phpParserNode;
    }

    public function hasChanged(): bool
    {
        return $this->hasChanged;
    }

    private function resolveFullyQualified(Type $type): ?FullyQualifiedObjectType
    {
        if ($type instanceof ShortenedObjectType || $type instanceof AliasedObjectType) {
            return new FullyQualifiedObjectType($type->getFullyQualifiedName());
        }

        if ($type instanceof FullyQualifiedObjectType) {
            return $type;
        }

        return null;
    }

    private function processFqnNameImport(
        PhpParserNode $phpParserNode,
        IdentifierTypeNode $identifierTypeNode,
        FullyQualifiedObjectType $fullyQualifiedObjectType,
        File $file
    ): ?IdentifierTypeNode {
        $parentNode = $identifierTypeNode->getAttribute(PhpDocAttributeKey::PARENT);
        if ($parentNode instanceof TemplateTagValueNode) {
            // might break
            return null;
        }

        // standardize to FQN
        if (str_starts_with($fullyQualifiedObjectType->getClassName(), '@')) {
            $fullyQualifiedObjectType = new FullyQualifiedObjectType(ltrim(
                $fullyQualifiedObjectType->getClassName(),
                '@'
            ));
        }

        if ($this->classNameImportSkipper->shouldSkipNameForFullyQualifiedObjectType(
            $file,
            $phpParserNode,
            $fullyQualifiedObjectType
        )) {
            return null;
        }

        $newNode = new IdentifierTypeNode($fullyQualifiedObjectType->getShortName());

        $fileNode = $file->getFileNode();
        if (! $fileNode instanceof FileNode) {
            return null;
        }

        $pendingImports = $fileNode->getPendingImports();

        // should skip because its already used
        if ($pendingImports->isShortImported($fullyQualifiedObjectType)
            && ! $pendingImports->isImportShortable($fullyQualifiedObjectType)) {
            return null;
        }

        if ($this->shouldImport($fileNode, $newNode, $identifierTypeNode, $fullyQualifiedObjectType)) {
            $pendingImports->addUseImport($fullyQualifiedObjectType);
            $this->hasChanged = true;

            return $newNode;
        }

        return null;
    }

    private function shouldImport(
        FileNode $fileNode,
        IdentifierTypeNode $newNode,
        IdentifierTypeNode $identifierTypeNode,
        FullyQualifiedObjectType $fullyQualifiedObjectType
    ): bool {
        if ($newNode->name === $identifierTypeNode->name) {
            return false;
        }

        if (str_starts_with($identifierTypeNode->name, '\\')) {
            if ($fullyQualifiedObjectType->getShortName() !== $fullyQualifiedObjectType->getClassName()) {
                return $fullyQualifiedObjectType->getShortName() !== ltrim($identifierTypeNode->name, '\\');
            }

            return true;
        }

        $className = $fullyQualifiedObjectType->getClassName();

        if (! $this->reflectionProvider->hasClass($className)) {
            return false;
        }

        $firstPath = Strings::before($identifierTypeNode->name, '\\' . $newNode->name);
        if ($firstPath === null) {
            return ! $fileNode->hasImport($fullyQualifiedObjectType);
        }

        if ($firstPath === '') {
            return true;
        }

        $namespaceParts = explode('\\', ltrim($firstPath, '\\'));
        return count($namespaceParts) > 1;
    }

    private function processNestedDoctrineAnnotation(DoctrineAnnotation $doctrineAnnotation): void
    {
        $currentPhpParserNode = $this->currentPhpParserNode;
        if (! $currentPhpParserNode instanceof PhpParserNode) {
            throw new ShouldNotHappenException();
        }

        $identifierTypeNode = new IdentifierTypeNode($doctrineAnnotation->name);
        $staticType = $this->identifierPhpDocTypeMapper->mapIdentifierTypeNode(
            $identifierTypeNode,
            $currentPhpParserNode
        );
        $staticType = $this->resolveFullyQualified($staticType);

        if (! $staticType instanceof FullyQualifiedObjectType) {
            return;
        }

        $file = $this->currentFileProvider->getFile();
        if (! $file instanceof File) {
            return;
        }

        $shortenedIdentifierTypeNode = $this->processFqnNameImport(
            $currentPhpParserNode,
            $identifierTypeNode,
            $staticType,
            $file
        );

        if (! $shortenedIdentifierTypeNode instanceof IdentifierTypeNode) {
            return;
        }

        $doctrineAnnotation->name = '@' . $shortenedIdentifierTypeNode->name;
    }

    private function enterDoctrineTagNode(
        PhpDocTagNode $phpDocTagNode,
        DoctrineAnnotation $doctrineAnnotation
    ): ?PhpDocTagNode {
        // special case for doctrine annotation
        if (! str_starts_with($phpDocTagNode->name, '@')) {
            return null;
        }

        $attributeClass = ltrim($phpDocTagNode->name, '@\\');
        $identifierTypeNode = new IdentifierTypeNode($attributeClass);

        $currentPhpParserNode = $this->currentPhpParserNode;
        if (! $currentPhpParserNode instanceof PhpParserNode) {
            throw new ShouldNotHappenException();
        }

        $staticType = $this->identifierPhpDocTypeMapper->mapIdentifierTypeNode(
            new IdentifierTypeNode($attributeClass),
            $currentPhpParserNode
        );
        $staticType = $this->resolveFullyQualified($staticType);

        if (! $staticType instanceof FullyQualifiedObjectType) {
            return null;
        }

        $file = $this->currentFileProvider->getFile();
        if (! $file instanceof File) {
            return null;
        }

        $importedName = $this->processFqnNameImport(
            $currentPhpParserNode,
            $identifierTypeNode,
            $staticType,
            $file
        );

        if (! $importedName instanceof IdentifierTypeNode) {
            return null;
        }

        // the printer prints the tag name, and the annotation name when it prints the tag again
        $phpDocTagNode->name = '@' . $importedName->name;
        $doctrineAnnotation->name = $phpDocTagNode->name;

        return $phpDocTagNode;
    }
}

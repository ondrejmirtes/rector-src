<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\Printer;

use Nette\Utils\Strings;
use PhpParser\Comment;
use PhpParser\Node\Stmt\InlineHTML;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocChildNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Printer\Printer;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;

/**
 * @see \Rector\Tests\BetterPhpDocParser\PhpDocInfo\PhpDocInfoPrinter\PhpDocInfoPrinterTest
 */
final readonly class PhpDocInfoPrinter
{
    /**
     * @see https://regex101.com/r/ME5Fcn/1
     */
    private const string NEW_LINE_WITH_SPACE_REGEX = "# (?<new_line>\r\n|\n)#";

    public function __construct(
        private Printer $printer,
        private EmptyPhpDocDetector $emptyPhpDocDetector,
        private DocBlockInliner $docBlockInliner,
    ) {
    }

    public function printNew(PhpDocInfo $phpDocInfo): string
    {
        $docContent = $this->printFresh($phpDocInfo->getPhpDocNode());
        if ($phpDocInfo->isSingleLine()) {
            return $this->docBlockInliner->inline($docContent);
        }

        if ($phpDocInfo->getNode() instanceof InlineHTML) {
            return '<?php' . PHP_EOL . $docContent . PHP_EOL . '?>';
        }

        return $docContent;
    }

    public function printFormatPreserving(PhpDocInfo $phpDocInfo): string
    {
        $phpDocNode = $phpDocInfo->getPhpDocNode();
        if ($this->emptyPhpDocDetector->isPhpDocNodeEmpty($phpDocNode)) {
            return '';
        }

        $tokenIterator = $phpDocInfo->getTokenIterator();

        // completely new one, or a tag was added to a single-line docblock
        if ($tokenIterator->getTokenCount() === 0 || (! $phpDocInfo->isSingleLine() && ! $this->hasNewLine(
            $tokenIterator
        ))) {
            $docContent = $this->printFresh($phpDocNode);
            if ($phpDocInfo->getNode() instanceof InlineHTML) {
                return '<?php' . PHP_EOL . $docContent . PHP_EOL . '?>';
            }

            return $docContent;
        }

        $this->reindexArrays($phpDocNode);

        $originalPhpDocNode = $phpDocInfo->getOriginalPhpDocNode();
        $phpDocNode = $this->removeTrailingEmptyLines($phpDocNode, $originalPhpDocNode);

        $docContent = $this->printer->printFormatPreserving($phpDocNode, $originalPhpDocNode, $tokenIterator);

        return Strings::replace(
            $docContent,
            self::NEW_LINE_WITH_SPACE_REGEX,
            static fn (array $match): string => (string) $match['new_line']
        );
    }

    /**
     * @return Comment[]
     */
    public function printToComments(PhpDocInfo $phpDocInfo): array
    {
        $printedPhpDocContents = $this->printFormatPreserving($phpDocInfo);
        return [new Comment($printedPhpDocContents)];
    }

    /**
     * The printer compares lists of nodes; unset() in rules leaves gaps in the keys
     */
    private function reindexArrays(Node $node): void
    {
        foreach (get_object_vars($node) as $propertyName => $value) {
            if ($value instanceof Node) {
                $this->reindexArrays($value);
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            if (! array_is_list($value)) {
                $node->{$propertyName} = array_values($value);
            }

            foreach ($value as $item) {
                if ($item instanceof Node) {
                    $this->reindexArrays($item);
                }
            }
        }
    }

    /**
     * Removing the last tag keeps the empty line above it, remove it too
     */
    private function removeTrailingEmptyLines(PhpDocNode $phpDocNode, PhpDocNode $originalPhpDocNode): PhpDocNode
    {
        $lastOriginalChildNode = $originalPhpDocNode->children[array_key_last($originalPhpDocNode->children)] ?? null;
        if ($this->isEmptyLine($lastOriginalChildNode)) {
            return $phpDocNode;
        }

        $children = $phpDocNode->children;
        while ($children !== [] && $this->isEmptyLine($children[array_key_last($children)])) {
            array_pop($children);
        }

        if (count($children) === count($phpDocNode->children)) {
            return $phpDocNode;
        }

        return new PhpDocNode($children);
    }

    private function isEmptyLine(?PhpDocChildNode $phpDocChildNode): bool
    {
        return $phpDocChildNode instanceof PhpDocTextNode && trim($phpDocChildNode->text) === '';
    }

    private function printFresh(PhpDocNode $phpDocNode): string
    {
        return $this->printer->print($phpDocNode);
    }

    private function hasNewLine(TokenIterator $tokenIterator): bool
    {
        return array_any($tokenIterator->getTokens(), fn (array $token): bool => $token[Lexer::TYPE_OFFSET] === Lexer::TOKEN_PHPDOC_EOL);
    }
}

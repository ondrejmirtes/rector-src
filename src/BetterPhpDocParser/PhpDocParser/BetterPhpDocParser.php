<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocParser;

use Nette\Utils\Strings;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use Rector\BetterPhpDocParser\Contract\PhpDocParser\PhpDocNodeDecoratorInterface;
use Rector\BetterPhpDocParser\NodeDecorator\ConstExprClassNameDecorator;
use Rector\BetterPhpDocParser\NodeDecorator\DoctrineAnnotationDecorator;
use Rector\BetterPhpDocParser\NodeDecorator\PhpDocTagGenericUsesDecorator;

/**
 * @see \Rector\Tests\BetterPhpDocParser\PhpDocParser\TagValueNodeReprint\TagValueNodeReprintTest
 */
final readonly class BetterPhpDocParser
{
    /**
     * @see https://regex101.com/r/JOKSmr/5
     */
    private const string MULTI_NEW_LINES_REGEX = '#(?<new_line>\r\n|\n){2,}#';

    /**
     * @var int[]
     */
    private const array NO_TEXT_TOKEN_TYPES = [
        Lexer::TOKEN_OPEN_PHPDOC,
        Lexer::TOKEN_CLOSE_PHPDOC,
        Lexer::TOKEN_PHPDOC_EOL,
        Lexer::TOKEN_HORIZONTAL_WS,
        Lexer::TOKEN_END,
    ];

    /**
     * @var PhpDocNodeDecoratorInterface[]
     */
    private array $phpDocNodeDecorators;

    public function __construct(
        private PhpDocParser $phpDocParser,
        ConstExprClassNameDecorator $constExprClassNameDecorator,
        DoctrineAnnotationDecorator $doctrineAnnotationDecorator,
        PhpDocTagGenericUsesDecorator $phpDocTagGenericUsesDecorator,
    ) {
        $this->phpDocNodeDecorators = [
            $constExprClassNameDecorator,
            $doctrineAnnotationDecorator,
            $phpDocTagGenericUsesDecorator,
        ];
    }

    public function parseWithNode(TokenIterator $tokenIterator, Node $node): PhpDocNode
    {
        try {
            $phpDocNode = $this->phpDocParser->parse($tokenIterator);
        } catch (ParserException) {
            // invalid Doctrine annotation arguments; keep the docblock as it is
            return $this->createTextPhpDocNode($tokenIterator);
        }

        foreach ($phpDocNode->children as $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if ($phpDocChildNode->value instanceof GenericTagValueNode) {
                $phpDocChildNode->value->value = Strings::replace(
                    $phpDocChildNode->value->value,
                    self::MULTI_NEW_LINES_REGEX,
                    static fn (array $match): string => (string) $match['new_line']
                );
            }
        }

        foreach ($this->phpDocNodeDecorators as $phpDocNodeDecorator) {
            $phpDocNodeDecorator->decorate($phpDocNode, $node);
        }

        return $phpDocNode;
    }

    /**
     * One text node with the content between "/**" and "*\/"
     */
    private function createTextPhpDocNode(TokenIterator $tokenIterator): PhpDocNode
    {
        $tokens = $tokenIterator->getTokens();
        $startIndex = 0;
        $endIndex = count($tokens) - 1;

        while ($startIndex < $endIndex && in_array($tokens[$startIndex][Lexer::TYPE_OFFSET], self::NO_TEXT_TOKEN_TYPES, true)) {
            ++$startIndex;
        }

        while ($endIndex > $startIndex && in_array($tokens[$endIndex][Lexer::TYPE_OFFSET], self::NO_TEXT_TOKEN_TYPES, true)) {
            --$endIndex;
        }

        $phpDocTextNode = new PhpDocTextNode($tokenIterator->getContentBetween($startIndex, $endIndex + 1));
        $phpDocTextNode->setAttribute(Attribute::START_INDEX, $startIndex);
        $phpDocTextNode->setAttribute(Attribute::END_INDEX, $endIndex);

        return new PhpDocNode([$phpDocTextNode]);
    }
}

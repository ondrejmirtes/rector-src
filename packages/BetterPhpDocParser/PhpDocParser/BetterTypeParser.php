<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocParser;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use Rector\BetterPhpDocParser\PhpDocInfo\TokenIteratorFactory;
use Rector\BetterPhpDocParser\ValueObject\StartAndEnd;

final class BetterTypeParser extends TypeParser
{
    /**
     * @var TokenIteratorFactory
     */
    private $tokenIteratorFactory;

    public function __construct(
        TokenIteratorFactory $tokenIteratorFactory,
        ?ConstExprParser $constExprParser = null
    ) {
        parent::__construct($constExprParser);
        $this->tokenIteratorFactory = $tokenIteratorFactory;
    }

    public function parse(TokenIterator $tokenIterator): TypeNode
    {
        $betterTokenIterator = $this->tokenIteratorFactory->createFromTokenIterator($tokenIterator);

        $startPosition = $betterTokenIterator->currentPosition();
        $typeNode = parent::parse($betterTokenIterator);
        $endPosition = $betterTokenIterator->currentPosition();

        $startAndEnd = new StartAndEnd($startPosition, $endPosition);
        $typeNode->setAttribute(StartAndEnd::class, $startAndEnd);

        return $typeNode;
    }
}
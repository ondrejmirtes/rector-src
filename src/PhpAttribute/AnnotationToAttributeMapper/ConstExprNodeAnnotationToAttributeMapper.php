<?php

declare(strict_types=1);

namespace Rector\PhpAttribute\AnnotationToAttributeMapper;

use Nette\Utils\Strings;
use PhpParser\BuilderHelpers;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use Rector\Exception\NotImplementedYetException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpAttribute\Contract\AnnotationToAttributeMapperInterface;

/**
 * @implements AnnotationToAttributeMapperInterface<ConstExprNode>
 */
final class ConstExprNodeAnnotationToAttributeMapper implements AnnotationToAttributeMapperInterface
{
    /**
     * The "*" at the start of each line of a multi-line string in a docblock
     */
    private const string STAR_COMMENT_REGEX = '#^[ \t]*\*#m';

    public function isCandidate(mixed $value): bool
    {
        return $value instanceof ConstExprNode;
    }

    /**
     * @param ConstExprNode $value
     */
    public function map($value): Expr
    {
        if ($value instanceof ConstExprStringNode) {
            return $this->mapString($value);
        }

        if ($value instanceof ConstFetchNode && $value->className !== '') {
            return new ClassConstFetch(new Name($value->className), $value->name);
        }

        if ($value instanceof ConstExprIntegerNode) {
            return BuilderHelpers::normalizeValue((int) $value->value);
        }

        if ($value instanceof ConstExprFloatNode) {
            return BuilderHelpers::normalizeValue((float) $value->value);
        }

        if ($value instanceof ConstExprTrueNode) {
            return BuilderHelpers::normalizeValue(true);
        }

        if ($value instanceof ConstExprFalseNode) {
            return BuilderHelpers::normalizeValue(false);
        }

        if ($value instanceof ConstExprNullNode) {
            return BuilderHelpers::normalizeValue(null);
        }

        throw new NotImplementedYetException();
    }

    private function mapString(ConstExprStringNode $constExprStringNode): String_
    {
        $value = Strings::replace($constExprStringNode->value, self::STAR_COMMENT_REGEX);

        $kind = str_contains($value, "'") && ! str_contains($value, "\n")
            ? String_::KIND_DOUBLE_QUOTED
            : String_::KIND_SINGLE_QUOTED;

        return new String_($value, [
            AttributeKey::KIND => $kind,
        ]);
    }
}

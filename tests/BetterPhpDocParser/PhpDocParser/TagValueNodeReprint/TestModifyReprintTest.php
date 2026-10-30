<?php

declare(strict_types=1);

namespace Rector\Tests\BetterPhpDocParser\PhpDocParser\TagValueNodeReprint;

use Nette\Utils\FileSystem;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\DoctrineConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArray;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArrayItem;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\Printer\PhpDocInfoPrinter;
use Rector\Exception\ShouldNotHappenException;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\Testing\Fixture\FixtureSplitter;
use Rector\Testing\Fixture\FixtureTempFileDumper;
use Rector\Testing\PHPUnit\AbstractLazyTestCase;
use Rector\Testing\TestingParser\TestingParser;

final class TestModifyReprintTest extends AbstractLazyTestCase
{
    private TestingParser $testingParser;

    private BetterNodeFinder $betterNodeFinder;

    private PhpDocInfoPrinter $phpDocInfoPrinter;

    private PhpDocInfoFactory $phpDocInfoFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testingParser = $this->make(TestingParser::class);
        $this->betterNodeFinder = $this->make(BetterNodeFinder::class);
        $this->phpDocInfoPrinter = $this->make(PhpDocInfoPrinter::class);
        $this->phpDocInfoFactory = $this->make(PhpDocInfoFactory::class);
    }

    public function test(): void
    {
        [$inputContent, $expectedContent] = FixtureSplitter::split(
            __DIR__ . '/FixtureModify/route_with_extra_methods.php.inc'
        );

        $phpDocInfo = $this->parseFileAndGetFirstNodeOfType($inputContent, ClassMethod::class);

        $doctrineAnnotation = $phpDocInfo->findOneByAnnotationClass('Symfony\Component\Routing\Annotation\Route');
        $this->assertInstanceOf(DoctrineAnnotation::class, $doctrineAnnotation);

        // this will extended tokens of first node
        $doctrineAnnotation->arguments[] = new DoctrineArgument(new IdentifierTypeNode('methods'), new DoctrineArray([
            new DoctrineArrayItem(null, new DoctrineConstExprStringNode('GET')),
            new DoctrineArrayItem(null, new DoctrineConstExprStringNode('HEAD')),
        ]));

        $expectedDocContent = trim($expectedContent);

        $printedPhpDocInfo = $this->phpDocInfoPrinter->printFormatPreserving($phpDocInfo);
        $this->assertSame($expectedDocContent, $printedPhpDocInfo);
    }

    public function testIntegerKeys(): void
    {
        $phpDocInfo = $this->parseFileAndGetFirstNodeOfType(<<<'PHP'
<?php

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\DiscriminatorMap({1="First", 5="Second"})
 */
final class SomeEntity
{
}
PHP
            , Class_::class);

        $doctrineAnnotation = $phpDocInfo->findOneByAnnotationClass('Doctrine\ORM\Mapping\DiscriminatorMap');
        $this->assertInstanceOf(DoctrineAnnotation::class, $doctrineAnnotation);

        $doctrineArray = $doctrineAnnotation->arguments[0]->value;
        $this->assertInstanceOf(DoctrineArray::class, $doctrineArray);
        $doctrineArray->items[] = new DoctrineArrayItem(
            new ConstExprIntegerNode('7'),
            new DoctrineConstExprStringNode('Third')
        );

        $this->assertSame(
            "/**\n * @ORM\DiscriminatorMap({1=\"First\", 5=\"Second\", 7=\"Third\"})\n */",
            $this->phpDocInfoPrinter->printFormatPreserving($phpDocInfo)
        );
    }

    /**
     * @param class-string<Node> $nodeType
     */
    private function parseFileAndGetFirstNodeOfType(string $fileContents, string $nodeType): PhpDocInfo
    {
        $fixtureFilePath = FixtureTempFileDumper::dump($fileContents);
        $nodes = $this->testingParser->parseFileToDecoratedNodes($fixtureFilePath);

        FileSystem::delete($fixtureFilePath);

        $node = $this->betterNodeFinder->findFirstInstanceOf($nodes, $nodeType);
        if (! $node instanceof Node) {
            throw new ShouldNotHappenException($fileContents);
        }

        return $this->phpDocInfoFactory->createFromNodeOrEmpty($node);
    }
}

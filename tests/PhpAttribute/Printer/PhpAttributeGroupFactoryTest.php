<?php

declare(strict_types=1);

namespace Rector\Tests\PhpAttribute\Printer;

use PhpParser\Node\Arg;
use PHPStan\PhpDocParser\Ast\ConstExpr\DoctrineConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineArgument;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Rector\PhpAttribute\NodeFactory\PhpAttributeGroupFactory;
use Rector\Testing\PHPUnit\AbstractLazyTestCase;

final class PhpAttributeGroupFactoryTest extends AbstractLazyTestCase
{
    private PhpAttributeGroupFactory $phpAttributeGroupFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpAttributeGroupFactory = $this->make(PhpAttributeGroupFactory::class);
    }

    public function testCreateFromClassWithItems(): void
    {
        $attributeGroup = $this->phpAttributeGroupFactory->createFromClassWithItems(
            'Symfony\Component\Routing\Annotation\Route',
            [
                'path' => '/path',
                'name' => 'action',
            ]
        );

        $this->assertNotEmpty($attributeGroup->attrs);
    }

    public function testCreateArgsFromItems(): void
    {
        $args = $this->phpAttributeGroupFactory->createArgsFromItems([
            new DoctrineArgument(new IdentifierTypeNode('path'), new DoctrineConstExprStringNode('/path')),
            new DoctrineArgument(new IdentifierTypeNode('name'), new DoctrineConstExprStringNode('action')),
        ]);

        $this->assertCount(2, $args);
        $this->assertContainsOnlyInstancesOf(Arg::class, $args);
    }
}

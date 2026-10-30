<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\ValueObject;

use Rector\PhpDocParser\PhpDocParser\ValueObject\PhpDocAttributeKey as NativePhpDocAttributeKey;

final class PhpDocAttributeKey
{
    /**
     * Fully qualified name of identifier type class
     */
    public const string RESOLVED_CLASS = 'resolved_class';

    public const string PARENT = NativePhpDocAttributeKey::PARENT;

    public const string ORIG_NODE = NativePhpDocAttributeKey::ORIG_NODE;
}

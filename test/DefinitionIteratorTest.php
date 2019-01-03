<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Upward\Test;

use Magento\Upward\Context;
use Magento\Upward\Definition;
use Magento\Upward\DefinitionIterator;
use Magento\Upward\Resolver\ResolverInterface;
use Magento\Upward\ResolverFactory;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use function BeBat\Verify\verify;

class DefinitionIteratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testDefinitionLoop(): void
    {
        $context    = new Context([]);
        $definition = new Definition([
            'key1' => 'key2',
            'key2' => 'key3',
            'key3' => 'key1',
        ]);

        $iterator = new DefinitionIterator($definition, $context);

        $resolverFactory = Mockery::mock('alias:' . ResolverFactory::class);

        $resolverFactory->shouldReceive('get')
            ->with(Mockery::any())
            ->andReturnNull();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Definition appears to contain a loop');

        $iterator->get('key1');
    }

    public function testDefinitionValueIsBuiltin(): void
    {
        $context    = new Context([]);
        $definition = new Definition(['key' => true]);
        $iterator   = new DefinitionIterator($definition, $context);

        verify($iterator->get('key'))->is()->true();

        // Key has been added to context
        verify($context->get('key'))->is()->true();

        verify($iterator->get('child-key', false))->is()->false();

        // Key was not added to context
        verify($context->has('child-key'))->is()->false();
    }

    public function testIteratingDefinitionTree(): void
    {
        $context    = new Context([]);
        $definition = new Definition([
            'key1' => 'key2',
            'key2' => true,
            'key4' => false,
        ]);

        $iterator = new DefinitionIterator($definition, $context);

        $resolverFactory = Mockery::mock('alias:' . ResolverFactory::class);

        $resolverFactory->shouldReceive('get')
            ->with(Mockery::any())
            ->andReturnNull();

        verify($iterator->get('key1'))->is()->true();

        // Both values added to context
        verify($context->get('key1'))->is()->true();
        verify($context->get('key2'))->is()->true();

        // Child definition is an address for a value in the root definition
        verify($iterator->get('key3', 'key4'))->is()->false();

        // Only value from root definition is added to context
        verify($context->has('key3'))->is()->false();
        verify($context->get('key4'))->is()->false();
    }

    public function testLookupInContext(): void
    {
        $context    = new Context(['key' => 'context value']);
        $definition = new Definition([]);
        $iterator   = new DefinitionIterator($definition, $context);

        verify($iterator->get('key'))->is()->sameAs('context value');
    }

    public function testResolverValueDefinition(): void
    {
        $context         = new Context([]);
        $definition      = new Definition(['key' => 'resolver-definition']);
        $childDefinition = new Definition(['child-key' => true]);
        $iterator        = new DefinitionIterator($definition, $context);
        $resolverFactory = Mockery::mock('alias:' . ResolverFactory::class);
        $mockResolver    = Mockery::mock(ResolverInterface::class);

        $resolverFactory->shouldReceive('get')
            ->with('resolver-definition')
            ->andReturn($mockResolver);

        $mockResolver->shouldReceive('setIterator')
            ->with($iterator);
        $mockResolver->shouldReceive('isValid')
            ->with($definition)
            ->andReturn(true);
        $mockResolver->shouldReceive('resolve')
            ->with('resolver-definition')
            ->andReturn($childDefinition);

        verify($iterator->get('key'))->is()->sameAs(['child-key' => true]);
        verify($context->get('key.child-key'))->is()->true();

        // Intermediate value was not added to context
        verify($context->has('child-key'))->is()->false();
    }

    public function testResolverValueInvalidDefinition(): void
    {
        $context         = new Context([]);
        $definition      = new Definition(['key' => ['child1' => 'value1']]);
        $iterator        = new DefinitionIterator($definition, $context);
        $resolverFactory = Mockery::mock('alias:' . ResolverFactory::class);
        $mockResolver    = Mockery::mock(ResolverInterface::class);

        $resolverFactory->shouldReceive('get')
            ->with(Definition::class)
            ->andReturn($mockResolver);

        $mockResolver->shouldReceive('setIterator')
            ->with($iterator);
        $mockResolver->shouldReceive('isValid')
            ->andReturn(false);
        $mockResolver->shouldNotHaveReceived('resolve');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Definition {"child1":"value1"} is not valid for');

        $iterator->get('key');
    }

    public function testScalarResolverValue(): void
    {
        $context         = new Context([]);
        $definition      = new Definition(['key' => 'resolver-definition']);
        $childDefinition = new Definition(['child-key' => 'resolver-for-child']);
        $iterator        = new DefinitionIterator($definition, $context);
        $resolverFactory = Mockery::mock('alias:' . ResolverFactory::class);
        $mockResolver    = Mockery::mock(ResolverInterface::class);

        $resolverFactory->shouldReceive('get')
            ->with('resolver-definition')
            ->andReturn($mockResolver);
        $resolverFactory->shouldReceive('get')
            ->with('resolver-for-child')
            ->andReturn($mockResolver);

        $mockResolver->shouldReceive('setIterator')
            ->with($iterator);
        $mockResolver->shouldReceive('resolve')
            ->with('resolver-definition')
            ->andReturn('resolver value');
        $mockResolver->shouldReceive('resolve')
            ->with('resolver-for-child')
            ->andReturn('child resolver value');

        verify($iterator->get('key'))->is()->sameAs('resolver value');
        verify($context->get('key'))->is()->sameAs('resolver value');

        verify($iterator->get('child-key', $childDefinition))->is()->sameAs('child resolver value');
        verify($context->has('child-key'))->is()->false();
    }
}
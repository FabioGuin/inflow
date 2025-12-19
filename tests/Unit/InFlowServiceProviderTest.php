<?php

namespace InFlow\Tests\Unit;

use InFlow\InFlowServiceProvider;
use InFlow\Tests\TestCase;

class InFlowServiceProviderTest extends TestCase
{
    public function test_service_provider_can_be_instantiated(): void
    {
        $provider = new InFlowServiceProvider($this->app);

        $this->assertInstanceOf(InFlowServiceProvider::class, $provider);
    }
}

<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_storefront_preflight_is_cached_without_disabling_credentials(): void
    {
        $config = require dirname(__DIR__, 3).'/config/cors.php';

        $this->assertContains('https://avinaq.com', $config['allowed_origins']);
        $this->assertTrue($config['supports_credentials']);
        $this->assertSame(600, $config['max_age']);
    }
}

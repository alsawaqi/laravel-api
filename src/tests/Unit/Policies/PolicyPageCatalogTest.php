<?php

namespace Tests\Unit\Policies;

use App\Support\Policies\PolicyPageCatalog;
use PHPUnit\Framework\TestCase;

class PolicyPageCatalogTest extends TestCase
{
    public function test_catalog_exposes_required_commerce_policy_pages(): void
    {
        $slugs = array_column(PolicyPageCatalog::all(), 'slug');

        $this->assertSame([
            'shipping',
            'returns',
            'privacy',
            'terms',
            'warranty',
            'faq',
        ], $slugs);
    }

    public function test_it_returns_policy_page_by_slug(): void
    {
        $page = PolicyPageCatalog::find('returns');

        $this->assertNotNull($page);
        $this->assertSame('returns', $page['slug']);
        $this->assertStringContainsString('Return', $page['title']);
        $this->assertNotEmpty($page['sections']);
    }
}

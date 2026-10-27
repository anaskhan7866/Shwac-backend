<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    public function test_admin_panel_page_is_served_with_its_assets(): void
    {
        $res = $this->get('/admin');

        $res->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('admin-assets/core.js', false)
            ->assertSee('window.SHWAC', false);

        foreach (['admin.css', 'core.js', 'pages-ops.js', 'pages-money.js', 'pages-stock.js', 'pages-admin.js', 'boot.js'] as $file) {
            $this->assertFileExists(public_path('admin-assets/'.$file));
        }
    }
}

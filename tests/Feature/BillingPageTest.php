<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_page_renders_with_selected_package()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('billing.index', ['package' => 'pro']))
            ->assertInertia(fn (Assert $page) => $page->component('billing/index')->where('selectedPackage', 'pro'));
    }

    public function test_billing_requires_login_and_home_page_is_public()
    {
        $this->get(route('billing.index'))->assertRedirect(route('login'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('welcome'));
    }
}

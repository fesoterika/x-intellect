<?php

namespace Tests\Feature;

use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordPressShortlinkRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_variants_use_one_redirect_record(): void
    {
        $redirect = Redirect::create(['from_path' => '/?p=317', 'to_url' => '/about/contacts', 'status_code' => 301]);

        foreach (['/?p=317', '/?utm_source=archive&p=317', '/index.php?p=317', '/?page_id=317', '/index.php?page_id=317&utm_source=archive'] as $url) {
            $this->get($url)->assertStatus(301)->assertRedirect('/about/contacts');
        }
        $this->head('/?p=317&utm_source=archive')->assertStatus(301)->assertRedirect('/about/contacts');
        $this->assertSame(1, Redirect::count());
        $this->assertSame(6, (int) $redirect->fresh()->hits);
    }

    public function test_explicit_query_rule_takes_precedence(): void
    {
        Redirect::create(['from_path' => '/?p=317', 'to_url' => '/about/contacts', 'status_code' => 301]);
        Redirect::create(['from_path' => '/?p=317&mode=special', 'to_url' => '/about/mission', 'status_code' => 301]);
        $this->get('/?p=317&mode=special')->assertRedirect('/about/mission');
    }

    public function test_other_paths_invalid_ids_and_post_do_not_use_shortlink(): void
    {
        $redirect = Redirect::create(['from_path' => '/?p=317', 'to_url' => '/about/contacts', 'status_code' => 301]);
        foreach (['/missing?p=317', '/?p[]=317', '/?p=317suffix', '/?p=-317', '/?p=0', '/?p=999999'] as $url) {
            $this->get($url)->assertHeaderMissing('Location');
        }
        $this->post('/?p=317')->assertHeaderMissing('Location');
        $this->assertSame(0, (int) $redirect->fresh()->hits);
    }
}

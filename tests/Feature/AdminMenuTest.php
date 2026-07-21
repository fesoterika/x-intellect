<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Страница «Навигация сайта»: шапка и футер — два независимых меню,
 * поэтому и редактируются они отдельными блоками.
 */
class AdminMenuTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_header_and_footer_are_separate_blocks(): void
    {
        MenuItem::create(['label' => 'Вики', 'url' => '/wiki', 'location' => 'header', 'position' => 1]);
        MenuItem::create(['label' => 'Правила', 'url' => '/rules', 'location' => 'footer', 'position' => 1]);

        $html = $this->actingAs($this->admin())->get(route('admin.menu.index'))->getContent();

        $this->assertStringContainsString('Шапка сайта', $html);
        $this->assertStringContainsString('Футер', $html);
        // Пункт шапки — выше заголовка футера, пункт футера — ниже
        $footerCaption = strpos($html, '>Футер</h3>');
        $this->assertLessThan($footerCaption, strpos($html, 'Вики'));
        $this->assertGreaterThan($footerCaption, strpos($html, 'Правила'));
    }

    /** Подпункт показывается внутри блока своего родителя. */
    public function test_child_is_listed_under_its_parent(): void
    {
        $parent = MenuItem::create(['label' => 'Вики', 'url' => '/wiki', 'location' => 'header', 'position' => 1]);
        MenuItem::create(['label' => 'Глоссарий', 'url' => '/glossary', 'location' => 'header', 'position' => 1, 'parent_id' => $parent->id]);
        MenuItem::create(['label' => 'Правила', 'url' => '/rules', 'location' => 'footer', 'position' => 1]);

        $html = $this->actingAs($this->admin())->get(route('admin.menu.index'))->getContent();

        $this->assertLessThan(strpos($html, '>Футер</h3>'), strpos($html, 'Глоссарий'));
    }

    /** В родители предлагаются только корневые пункты шапки. */
    public function test_footer_items_are_not_offered_as_parents(): void
    {
        MenuItem::create(['label' => 'Шапочный', 'url' => '/wiki', 'location' => 'header', 'position' => 1]);
        MenuItem::create(['label' => 'Футерный', 'url' => '/rules', 'location' => 'footer', 'position' => 1]);

        $html = $this->actingAs($this->admin())->get(route('admin.menu.index'))->getContent();

        $this->assertStringContainsString('Шапочный</option>', $html);
        $this->assertStringNotContainsString('Футерный</option>', $html);
    }

    /** Подпункт наследует расположение родителя: иначе он пропал бы с сайта. */
    public function test_child_inherits_parent_location(): void
    {
        $parent = MenuItem::create(['label' => 'Вики', 'url' => '/wiki', 'location' => 'header', 'position' => 1]);

        $this->actingAs($this->admin())->post(route('admin.menu.store'), [
            'label' => 'Глоссарий',
            'url' => '/glossary',
            'location' => 'footer',
            'parent_id' => $parent->id,
        ]);

        $this->assertSame('header', MenuItem::where('label', 'Глоссарий')->first()->location);
    }
}

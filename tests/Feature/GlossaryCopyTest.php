<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Текст под кнопкой «копировать» и meta description термина. Определения
 * почти всегда открываются самим термином, и подпись перед ними давала
 * «Торы биоэкрана - Торы биоэкрана – данные структуры…».
 */
class GlossaryCopyTest extends TestCase
{
    use RefreshDatabase;

    private function term(string $term, string $definition): GlossaryTerm
    {
        return GlossaryTerm::create([
            'term' => $term,
            'slug' => 'test-'.md5($term),
            'definition' => $definition,
        ]);
    }

    public function test_term_is_not_repeated_when_definition_opens_with_it(): void
    {
        $term = $this->term('Торы биоэкрана', '<p>Торы биоэкрана – данные структуры появляются в биоэкране.</p>');

        $this->assertSame('Торы биоэкрана – данные структуры появляются в биоэкране.', $term->termWithDefinition());
    }

    /** Регистр в определении бывает любым — повтор всё равно повтор. */
    public function test_match_ignores_case(): void
    {
        $term = $this->term('Биоэкран', '<p>БИОЭКРАН - структура над головой человека.</p>');

        $this->assertSame('БИОЭКРАН - структура над головой человека.', $term->termWithDefinition());
    }

    /** Без термина в определении копия потеряла бы имя — подставляем. */
    public function test_term_is_prefixed_when_definition_does_not_open_with_it(): void
    {
        $term = $this->term('Посредники', '<p>Ядро команды сайта - люди, с помощью которых производится выход на каналы.</p>');

        $this->assertSame(
            'Посредники - Ядро команды сайта - люди, с помощью которых производится выход на каналы.',
            $term->termWithDefinition(),
        );
    }

    /** Уточнение в скобках в определение не переносится: повтора всё равно нет. */
    public function test_parenthetical_clarification_is_ignored_in_match(): void
    {
        $term = $this->term(
            'Энергетический дубликат полевой оболочки (оболочечный двойник)',
            '<p>Энергетический дубликат полевой оболочки – при высокой энергонасыщенности.</p>',
        );

        $this->assertSame('Энергетический дубликат полевой оболочки – при высокой энергонасыщенности.', $term->termWithDefinition());
    }

    /** Скобки в термине не мешают подставить его, когда в определении его нет. */
    public function test_term_with_parentheses_is_prefixed_in_full(): void
    {
        $term = $this->term('Внеземные Цивилизации (ВЦ)', '<p>Классификация внеземных разумов.</p>');

        $this->assertSame('Внеземные Цивилизации (ВЦ) - Классификация внеземных разумов.', $term->termWithDefinition());
    }

    public function test_copy_button_and_meta_description_carry_text_without_repeat(): void
    {
        $this->term('Торы биоэкрана', '<p>Торы биоэкрана – данные структуры появляются в биоэкране.</p>');

        $page = $this->get('/glossary?term='.GlossaryTerm::first()->slug)->assertOk();

        $page->assertDontSee('Торы биоэкрана - Торы биоэкрана', false);
        $page->assertSee('<meta name="description" content="Торы биоэкрана – данные структуры появляются в биоэкране.">', false);
    }
}

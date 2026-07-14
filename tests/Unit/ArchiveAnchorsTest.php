<?php

namespace Tests\Unit;

use App\Services\ArchiveHtmlCleaner;
use PHPUnit\Framework\TestCase;

class ArchiveAnchorsTest extends TestCase
{
    private function clean(string $html): string
    {
        return (new ArchiveHtmlCleaner)->clean($html, sys_get_temp_dir());
    }

    public function test_a_name_is_converted_to_id(): void
    {
        $out = $this->clean('<p><a name="0001"></a>Начало главы.</p>');

        $this->assertStringContainsString('<a id="0001"></a>', $out);
        $this->assertStringNotContainsString('name=', $out);
    }

    public function test_a_id_survives(): void
    {
        $out = $this->clean('<p><a id="glava-2" href="#top">Наверх</a></p>');

        $this->assertStringContainsString('id="glava-2"', $out);
    }

    public function test_heading_id_survives(): void
    {
        $out = $this->clean('<h2 id="razdel">Раздел</h2>');

        $this->assertStringContainsString('<h2 id="razdel">', $out);
    }

    public function test_mw_headline_span_id_hoisted_to_heading(): void
    {
        $out = $this->clean('<h2><span class="mw-headline" id="Sekciya_1">Секция 1</span></h2>');

        $this->assertStringContainsString('<h2 id="Sekciya_1">', $out);
        $this->assertStringContainsString('Секция 1', $out);
    }

    public function test_id_value_is_sanitized(): void
    {
        $out = $this->clean('<p><a name="bad id \'quote\'"></a>Текст.</p>');

        $this->assertStringContainsString('id="badidquote"', $out);
    }

    public function test_other_attributes_still_stripped(): void
    {
        $out = $this->clean('<h3 id="x" style="color:red" class="big">Заголовок</h3>');

        $this->assertStringContainsString('<h3 id="x">', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('class="big"', $out);
    }

    public function test_table_cells_keep_span_attributes(): void
    {
        $out = $this->clean('<table><tr><td colspan="2" bgcolor="#fff">Ячейка</td></tr></table>');

        $this->assertStringContainsString('colspan="2"', $out);
        $this->assertStringNotContainsString('bgcolor', $out);
    }
}

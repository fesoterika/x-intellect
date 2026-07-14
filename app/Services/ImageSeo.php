<?php

namespace App\Services;

/**
 * SEO-атрибуты изображений в теле материала: каждому <img> без alt
 * проставляется описательный alt по названию материала и порядковому
 * номеру («Изображение к материалу «…» №N»). Прогоняется при сохранении
 * страницы (см. App\Observers\PageObserver). Непустой alt, заданный
 * редактором вручную, не перезаписывается.
 */
class ImageSeo
{
    public function process(?string $html, string $title): ?string
    {
        if (blank($html) || ! str_contains($html, '<img')) {
            return $html;
        }

        $n = 0;

        return preg_replace_callback('/<img\b([^>]*?)\/?>/i', function ($m) use (&$n, $title) {
            $n++;
            $attrs = $m[1];
            $alt = $this->altText($title, $n);

            if (preg_match('/\salt\s*=\s*(["\'])(.*?)\1/is', $attrs, $found)) {
                // Непустой alt редактора сохраняем; пустой — заполняем
                if (trim($found[2]) !== '') {
                    return '<img'.$attrs.'>';
                }

                $attrs = preg_replace(
                    '/\salt\s*=\s*(["\']).*?\1/is',
                    ' alt="'.$this->esc($alt).'"',
                    $attrs,
                    1,
                );

                return '<img'.$attrs.'>';
            }

            return '<img alt="'.$this->esc($alt).'"'.$attrs.'>';
        }, $html);
    }

    protected function altText(string $title, int $n): string
    {
        return 'Изображение к материалу «'.trim($title).'» - №'.$n;
    }

    protected function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

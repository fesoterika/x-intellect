<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageRevision;
use Illuminate\Http\Request;

/**
 * Правка истории изменений на форме страницы: заголовок ревизии, причина
 * правки, дата архивной редакции, удаление лишней записи.
 *
 * Служебная пометка note («Отредактирована вручную …») не редактируется:
 * по ней импортёры отличают ручные правки и не затирают их (см.
 * ImportOfflineWiki, ImportWaybackWiki).
 */
class PageRevisionController extends Controller
{
    public function update(Request $request, Page $page, PageRevision $revision)
    {
        abort_unless($revision->page_id === $page->id, 404);

        $revision->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'archived_at' => ['nullable', 'date'],
        ]));

        return back()->with('status', 'Запись истории обновлена.');
    }

    public function destroy(Page $page, PageRevision $revision)
    {
        abort_unless($revision->page_id === $page->id, 404);

        $revision->delete();

        return back()->with('status', 'Запись истории удалена.');
    }
}

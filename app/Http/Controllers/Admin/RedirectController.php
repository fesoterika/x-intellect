<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RedirectController extends Controller
{
    public function index(Request $request)
    {
        $redirects = Redirect::query()
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.mb_strtolower($term).'%';

                $q->where(function ($sub) use ($like) {
                    $sub->whereRaw('LOWER(from_path) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(to_url) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(comment) LIKE ?', [$like]);
                });
            })
            ->orderBy('from_path')
            ->paginate(50)
            ->withQueryString();

        return view('admin.redirects.index', [
            'redirects' => $redirects,
        ]);
    }

    public function store(Request $request)
    {
        Redirect::create($this->validated($request));

        return back()->with('status', 'Редирект добавлен.');
    }

    public function update(Request $request, Redirect $redirect)
    {
        $redirect->update($this->validated($request, $redirect));

        return back()->with('status', 'Редирект обновлён.');
    }

    public function destroy(Redirect $redirect)
    {
        $redirect->delete();

        return back()->with('status', 'Редирект удалён.');
    }

    protected function validated(Request $request, ?Redirect $redirect = null): array
    {
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:255', Rule::unique('redirects', 'from_path')->ignore($redirect)],
            'to_url' => ['required', 'string', 'max:2048'],
            'status_code' => ['required', Rule::in([301, 302])],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $data['from_path'] = '/'.ltrim($data['from_path'], '/');

        return $data;
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpRenderer;
use App\Support\Help\HelpRepository;
use Illuminate\Http\Request;

/**
 * Support > Help. Everything a user can list, search or open is decided by HelpRepository::canView(); anything
 * they may not see answers 404 (never 403) so the existence of staff-only articles is not revealed.
 */
class HelpController extends Controller
{
    public function index(Request $request)
    {
        $help = HelpRepository::make();
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));

        return view('help.index', [
            'query' => $query,
            'results' => $query !== '' ? $help->search($user, $query) : null,
            'groups' => $help->categoriesFor($user),
        ]);
    }

    public function category(Request $request, string $category)
    {
        $help = HelpRepository::make();
        $cat = $help->category($request->user(), $category);
        abort_if($cat === null, 404);

        return view('help.category', ['category' => $cat]);
    }

    public function show(Request $request, string $category, string $slug, HelpRenderer $renderer)
    {
        $help = HelpRepository::make();
        $user = $request->user();
        $article = $help->find($user, $category, $slug);
        abort_if($article === null, 404);

        return view('help.show', [
            'article' => $article,
            'category' => $help->category($user, $article->category),
            'html' => $renderer->render($article),
            'related' => $help->related($user, $article),
        ]);
    }
}

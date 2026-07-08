<?php

namespace App\Http\Controllers;

use App\Services\Search\HybridSearcher;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request, HybridSearcher $searcher): View
    {
        $query = (string) $request->input('q', '');
        $projectId = $request->input('project_id');
        $category = $request->input('category');

        $results = [];

        if ($query !== '') {
            $results = $searcher->search($query, $projectId, $category);
        }

        return view('search', [
            'query' => $query,
            'results' => $results,
            'projectId' => $projectId,
            'category' => $category,
        ]);
    }
}

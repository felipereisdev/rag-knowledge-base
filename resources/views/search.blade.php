<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search — RAG Knowledge Base</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; }
        .search-form { margin-bottom: 2rem; }
        .search-form input, .search-form select { padding: 0.5rem; margin-right: 0.5rem; }
        .result { border-bottom: 1px solid #eee; padding: 1rem 0; }
        .result h3 { margin: 0 0 0.5rem; }
        .result .meta { font-size: 0.875rem; color: #666; }
        .result .snippet { margin-top: 0.5rem; line-height: 1.5; }
        .matched-by { display: inline-block; background: #e0e7ff; color: #4338ca; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; margin-right: 0.25rem; }
        mark { background: #fef08a; padding: 0 0.125rem; }
    </style>
</head>
<body>
    <h1>RAG Knowledge Base — Search</h1>

    <form method="GET" action="/search" class="search-form">
        <input type="text" name="q" value="{{ htmlspecialchars($query) }}" placeholder="Search..." size="40" autofocus>
        <select name="project_id">
            <option value="">All projects</option>
            @foreach (\App\Models\Project::all() as $project)
                <option value="{{ $project->id }}" {{ $projectId === $project->id ? 'selected' : '' }}>{{ $project->name }}</option>
            @endforeach
        </select>
        <select name="category">
            <option value="">All categories</option>
            @foreach (['business-rule', 'design-decision', 'architecture', 'documentation', 'insight', 'convention', 'constraint'] as $cat)
                <option value="{{ $cat }}" {{ $category === $cat ? 'selected' : '' }}>{{ ucfirst(str_replace('-', ' ', $cat)) }}</option>
            @endforeach
        </select>
        <button type="submit">Search</button>
    </form>

    @if ($query !== '')
        <p>Found {{ count($results) }} results for "{{ htmlspecialchars($query) }}"</p>

        @foreach ($results as $result)
            <div class="result">
                <h3>
                    <a href="/martis/resources/knowledge-entries/{{ $result->entryId }}">{{ $result->title }}</a>
                </h3>
                <div class="meta">
                    {{ __('rag.search.fusion_score') }}:
                    {{ number_format($result->fusionScore, 4) }} ·
                    {{ __('rag.search.semantic_similarity') }}:
                    {{ $result->semanticSimilarity !== null ? number_format($result->semanticSimilarity, 4) : '—' }} ·
                    {{ __('rag.search.category') }}: {{ $result->category }}
                    @if ($result->graphExpanded)
                        · <span class="matched-by">{{ __('rag.search.graph_expanded') }}</span>
                    @endif
                    @foreach ($result->matchedBy as $matched)
                        <span class="matched-by">{{ $matched }}</span>
                    @endforeach
                </div>
                <div class="snippet">
                    {!! $result->snippet !!}
                </div>
            </div>
        @endforeach
    @endif
</body>
</html>

<?php

return [
    'categories' => [
        'business-rule' => 'Business Rule',
        'design-decision' => 'Design Decision',
        'architecture' => 'Architecture',
        'documentation' => 'Documentation',
        'insight' => 'Insight',
        'convention' => 'Convention',
        'constraint' => 'Constraint',
    ],
    'statuses' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
    'languages' => [
        'en' => 'English',
        'pt' => 'Portuguese',
        'es' => 'Spanish',
    ],
    'fields' => [
        'project' => 'Project',
        'category' => 'Category',
        'title' => 'Title',
        'content' => 'Content',
        'status' => 'Status',
        'source' => 'Source',
        'author' => 'Author',
        'tags' => 'Tags',
        'entities' => 'Entities',
        'metadata' => 'Metadata',
        'created_at' => 'Created At',
        'language' => 'Language',
        'source_help' => 'manual, mcp, import, or cli.',
        'language_help' => 'Affects FTS stemming.',
    ],
    'filters' => [
        'project' => 'Project',
        'category' => 'Category',
        'status' => 'Status',
        'created_between' => 'Created Between',
        'select' => 'Select...',
    ],
    'dashboard' => [
        'main' => 'Main',
        'projects' => 'Projects',
        'total_entries' => 'Total Entries',
        'pending_approvals' => 'Pending Approvals',
        'chunk_embeddings' => 'Chunk Embeddings',
    ],
];

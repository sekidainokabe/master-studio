<?php

namespace App\Http\Controllers;

use App\Enums\ChangesetStatus;
use App\Http\Requests\StoreChangesetRequest;
use App\Http\Resources\ChangesetResource;
use App\Models\Changeset;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ChangesetController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ChangesetStatus::class)],
            'author_id' => ['nullable', 'integer'],
        ]);

        $changesets = Changeset::query()
            ->with('author:id,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['author_id'] ?? null, fn ($q, int|string $authorId) => $q->where('author_id', $authorId))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return ChangesetResource::collection($changesets);
    }

    public function store(StoreChangesetRequest $request): JsonResponse
    {
        $changeset = Changeset::create([
            ...$request->validated(),
            'status' => ChangesetStatus::Draft,
            'author_id' => $request->user()->id,
            // 承認時に「作成後に本番が変わっていないか」を確かめる（競合検出）ため、作成時点の最新リリースを記録する
            'base_release_id' => Release::query()->orderByDesc('version')->value('id'),
        ]);

        return $this->detail($changeset)->response()->setStatusCode(201);
    }

    public function show(Changeset $changeset): ChangesetResource
    {
        return $this->detail($changeset);
    }

    private function detail(Changeset $changeset): ChangesetResource
    {
        return new ChangesetResource(
            $changeset->load(['author:id,name', 'reviewer:id,name', 'baseRelease:id,version'])->loadCount('items'),
        );
    }
}

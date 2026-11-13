<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ChangesetStatus;
use App\Http\Requests\StoreChangesetRequest;
use App\Http\Requests\UpdateChangesetRequest;
use App\Http\Resources\ChangesetResource;
use App\Models\Changeset;
use App\Models\Release;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChangesetController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly AuditLogger $audit) {}

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
        $changeset = DB::transaction(function () use ($request) {
            $changeset = Changeset::create([
                ...$request->validated(),
                'status' => ChangesetStatus::Draft,
                'author_id' => $request->user()->id,
                // 承認時に「作成後に本番が変わっていないか」を確かめる（競合検出）ため、作成時点の最新リリースを記録する
                'base_release_id' => Release::query()->orderByDesc('version')->value('id'),
            ]);
            $this->audit->record($request->user(), AuditAction::ChangesetCreated, $changeset, ['title' => $changeset->title]);

            return $changeset;
        });

        return $this->detail($changeset)->response()->setStatusCode(201);
    }

    public function show(Changeset $changeset): ChangesetResource
    {
        return $this->detail($changeset);
    }

    public function update(UpdateChangesetRequest $request, Changeset $changeset): ChangesetResource
    {
        DB::transaction(function () use ($request, $changeset) {
            $changeset->fill($request->validated());
            $changed = $changeset->getDirty();
            if ($changed === []) {
                return;
            }
            // 変わった項目だけ、前と後を残す
            $before = array_intersect_key($changeset->getOriginal(), $changed);
            $changeset->save();
            $this->audit->record($request->user(), AuditAction::ChangesetUpdated, $changeset, ['before' => $before, 'after' => $changed]);
        });

        return $this->detail($changeset);
    }

    /** 下書きの取り下げ。中の変更（changeset_items）も外部キーの cascade で一緒に消える */
    public function destroy(Request $request, Changeset $changeset): Response
    {
        Gate::authorize('delete', $changeset);

        DB::transaction(function () use ($request, $changeset) {
            // 変更セットは消えるため、履歴を読んで分かるようタイトルと件数を残す
            $this->audit->record($request->user(), AuditAction::ChangesetDeleted, $changeset, [
                'title' => $changeset->title,
                'item_count' => $changeset->items()->count(),
            ]);
            $changeset->delete();
        });

        return response()->noContent();
    }

    private function detail(Changeset $changeset): ChangesetResource
    {
        return ChangesetResource::detail($changeset);
    }
}

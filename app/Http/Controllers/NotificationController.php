<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Html;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $trash  = $request->boolean('trash');
        $query  = $this->owned($userId, $trash)->orderByDesc('created_at');

        if (! $trash && $request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $limit  = min((int) $request->query('limit', 30), 100);
        $offset = max((int) $request->query('offset', 0), 0);
        $total  = (clone $query)->count();

        $items = $query->skip($offset)->take($limit)->get()->map(function ($item) {
            $item->body = $item->body ? Html::toText($item->body) : null;

            return $item;
        });

        return response()->json([
            'items'  => $items,
            'total'  => $total,
            'unread' => $this->owned($userId, false)->whereNull('read_at')->count(),
            'trash'  => $this->owned($userId, true)->count(),
            'kinds'  => $this->owned($userId, $trash)
                ->selectRaw('type, count(*) as total')
                ->groupBy('type')
                ->pluck('total', 'type'),
        ]);
    }

    public function markRead(Request $request, $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => 'read']);
    }

    public function markAllRead(Request $request)
    {
        $this->owned($request->user()->id, false)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => 'read']);
    }

    public function trash(Request $request, $id)
    {
        $notification = $this->owned($request->user()->id, false)->findOrFail($id);
        $notification->update(['trashed_at' => now()]);

        return response()->json(['data' => 'trashed']);
    }

    public function restore(Request $request, $id)
    {
        $notification = $this->owned($request->user()->id, true)->findOrFail($id);
        $notification->update(['trashed_at' => null]);

        return response()->json(['data' => 'restored']);
    }

    private function owned(int $userId, bool $trash): Builder
    {
        return Notification::where('user_id', $userId)
            ->when($trash, fn ($query) => $query->whereNotNull('trashed_at'))
            ->when(! $trash, fn ($query) => $query->whereNull('trashed_at'));
    }
}

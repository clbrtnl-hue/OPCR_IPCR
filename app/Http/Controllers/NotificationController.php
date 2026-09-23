<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Html;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $query  = Notification::where('user_id', $userId)->orderByDesc('created_at');

        if ($request->boolean('unread')) {
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
            'unread' => Notification::where('user_id', $userId)->whereNull('read_at')->count(),
            'kinds'  => Notification::where('user_id', $userId)
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
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => 'read']);
    }
}

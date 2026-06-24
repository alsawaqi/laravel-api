<?php

namespace App\Http\Controllers;

use App\Models\ConxDatabaseNotification;
use Illuminate\Http\Request;

class CustomerNotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'read' => ['nullable', 'in:all,read,unread'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        $read = $request->query('read', 'all');
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = $this->baseQuery((int) $user->id)
            ->when($read === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($read === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($notification) => $this->toApi($notification))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'unread_count' => $this->unreadTotal((int) $user->id),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread_count' => $this->unreadTotal((int) $request->user()->id),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $this->baseQuery((int) $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        if (!$notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $this->toApi($notification->fresh()),
            'unread_count' => $this->unreadTotal((int) $request->user()->id),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $this->baseQuery((int) $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marked as read.',
            'unread_count' => 0,
        ]);
    }

    private function unreadTotal(int $userId): int
    {
        return $this->baseQuery($userId)->whereNull('read_at')->count();
    }

    private function baseQuery(int $userId)
    {
        return ConxDatabaseNotification::query()
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', $userId);
    }

    private function toApi(ConxDatabaseNotification $notification): array
    {
        $data = is_array($notification->data)
            ? $notification->data
            : json_decode((string) ($notification->data ?? '{}'), true);

        $data = is_array($data) ? $data : [];

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'category' => $data['category'] ?? null,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'url' => $data['url'] ?? null,
            'data' => $data,
            'read_at' => optional($notification->read_at)?->toDateTimeString(),
            'created_at' => optional($notification->created_at)?->toDateTimeString(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCategory;
use App\Models\CalendarEvent;
use App\Models\PcrOutput;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = AttendanceCategory::ensure();

        return response()->json([
            'can_manage' => AttendanceCategory::manageableBy($request->user()),
            'can_link'   => AttendanceCategory::linkableBy($request->user()),
            'categories' => $categories->map(fn (AttendanceCategory $category) => $this->payload($category))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->guard($request->user());

        $data = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $name = trim($data['name']);
        $this->uniqueName($name);

        $category = AttendanceCategory::create([
            'name'       => $name,
            'slug'       => $this->slug($name),
            'source'     => 'event',
            'system'     => false,
            'sort_order' => (int) AttendanceCategory::query()->max('sort_order') + 1,
        ]);

        return response()->json(['category' => $this->payload($category)], 201);
    }

    public function update(Request $request, $id)
    {
        $this->guard($request->user());

        $category = AttendanceCategory::query()->findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:120',
        ]);

        $name = trim($data['name']);
        $this->uniqueName($name, $category->id);

        $category->update(['name' => $name]);

        return response()->json(['category' => $this->payload($category->fresh())]);
    }

    public function destroy(Request $request, $id)
    {
        $this->guard($request->user());

        $category = AttendanceCategory::query()->findOrFail($id);

        if ($category->system || $category->source === 'daily') {
            return response()->json([
                'message' => 'The built-in categories stay on the list. Rename one, or add your own.',
            ], 422);
        }

        $used = CalendarEvent::query()->where('category_id', $category->id)->exists()
            || PcrOutput::query()->where('attendance_category_id', $category->id)->exists();

        if ($used) {
            return response()->json([
                'message' => 'That category is used by an event or a support line.',
            ], 422);
        }

        $category->delete();

        return response()->json(['data' => 'deleted']);
    }

    private function guard(User $user): void
    {
        if (! AttendanceCategory::manageableBy($user)) {
            abort(response()->json([
                'message' => 'Only a head can change attendance categories.',
            ], 403));
        }
    }

    private function uniqueName(string $name, ?int $ignoreId = null): void
    {
        $taken = AttendanceCategory::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            abort(response()->json([
                'message' => 'That category already exists.',
                'errors'  => ['name' => ['That category already exists.']],
            ], 422));
        }
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'category';
        $slug = $base;
        $n = 2;

        while (AttendanceCategory::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }

    private function payload(AttendanceCategory $category): array
    {
        return [
            'id'       => $category->id,
            'name'     => $category->name,
            'slug'     => $category->slug,
            'source'   => $category->source,
            'system'   => (bool) $category->system,
            'rate_key' => $category->rateKey(),
        ];
    }
}

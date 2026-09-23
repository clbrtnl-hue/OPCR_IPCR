<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('orgUnit')->orderByPerson();

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($unit = $request->query('org_unit_id')) {
            $query->where('org_unit_id', $unit);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    public function options()
    {
        return response()->json(
            User::where('status', 'active')
                ->orderByPerson()
                ->get(['id', 'name', 'role', 'position_title', 'org_unit_id'])
        );
    }

    public function store(Request $request)
    {
        $id = $request->input('id');

        $data = $request->validate([
            'id'             => 'nullable|integer|exists:users,id',
            'prefix'         => 'nullable|string|max:20',
            'first_name'     => 'required|string|max:80',
            'middle_initial' => 'nullable|string|max:10',
            'last_name'      => 'required|string|max:80',
            'suffix'         => 'nullable|string|max:20',
            'credentials'    => 'nullable|string|max:60',
            'email'          => ['required', 'email', 'max:150', Rule::unique('users')->ignore($id)],
            'password'       => $id ? 'nullable|string|min:8' : 'required|string|min:8',
            'role'           => ['required', Rule::in(User::ROLES)],
            'status'         => ['nullable', Rule::in(['active', 'inactive'])],
            'position_title' => 'nullable|string|max:150',
            'org_unit_id'    => 'nullable|integer|exists:org_units,id',
        ]);

        if ($data['role'] === 'president') {
            $clash = User::where('role', 'president')
                ->where('status', 'active')
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->first();

            if ($clash) {
                return response()->json([
                    'message' => "{$clash->name} is already the active President. Deactivate or change that account first.",
                ], 409);
            }
        }

        $attributes = [
            'prefix'         => $data['prefix'] ?? null,
            'first_name'     => $data['first_name'],
            'middle_initial' => $data['middle_initial'] ?? null,
            'last_name'      => $data['last_name'],
            'suffix'         => $data['suffix'] ?? null,
            'credentials'    => $data['credentials'] ?? null,
            'email'          => $data['email'],
            'role'           => $data['role'],
            'status'         => $data['status'] ?? 'active',
            'position_title' => $data['position_title'] ?? null,
            'org_unit_id'    => $data['org_unit_id'] ?? null,
        ];

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        if ($id) {
            $user    = User::findOrFail($id);
            $changes = ActivityLog::diff($user, $attributes);
            $user->update($attributes);

            ActivityLog::record('User', $user->id, 'update', "Updated account {$user->name}", $changes);

            return response()->json(['data' => 'updated', 'user' => $user->load('orgUnit')]);
        }

        $user = User::create($attributes);

        ActivityLog::record('User', $user->id, 'create', "Created account {$user->name} ({$user->role})");

        return response()->json(['data' => 'created', 'user' => $user->load('orgUnit')], 201);
    }

    public function avatar(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp',
        ]);

        $actor = $request->user();

        if (! $actor->isAdmin() && (int) $id !== (int) $actor->id) {
            return response()->json(['message' => 'You can only change your own photo.'], 403);
        }

        $user = User::findOrFail($id);
        $file = $request->file('file');
        $name = 'avatar-' . uniqid() . '.' . $file->getClientOriginalExtension();

        $file->move(public_path('uploads/profile'), $name);

        if ($user->image) {
            $old = public_path('uploads/profile/' . $user->image);

            if (is_file($old)) {
                @unlink($old);
            }
        }

        $user->update(['image' => $name]);

        ActivityLog::record('User', $user->id, 'avatar', "Updated photo for {$user->name}");

        return response()->json(['data' => 'updated', 'user' => $user->fresh()]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ((int) $id === (int) Auth::id()) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 409);
        }

        $user->update(['status' => 'inactive']);

        ActivityLog::record('User', $user->id, 'deactivate', "Deactivated account {$user->name}");

        return response()->json(['data' => 'deactivated']);
    }
}

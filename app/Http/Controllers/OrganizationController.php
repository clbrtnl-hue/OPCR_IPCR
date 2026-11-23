<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Identity and branding for the login screen and the printed form header,
     * which used to hardcode "Opol Community College". Public: the login screen
     * needs it before anyone has signed in.
     */
    public function show(CurrentOrganization $current)
    {
        $organization = $current->get();

        if (! $organization) {
            return response()->json(['message' => 'No organization has been set up yet.'], 404);
        }

        return response()->json($this->payload($organization));
    }

    /**
     * Admin edits the college name, address, signatory title and logo used on
     * the login screen, sidebar and printed forms.
     */
    public function update(Request $request, CurrentOrganization $current)
    {
        $organization = $current->get();

        if (! $organization) {
            return response()->json(['message' => 'No organization has been set up yet.'], 404);
        }

        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'short_name' => 'nullable|string|max:60',
            'address'    => 'nullable|string|max:255',
            'head_title' => 'nullable|string|max:120',
            'logo'       => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp',
        ]);

        $before = $organization->only(['name', 'short_name', 'address', 'head_title', 'logo_path']);

        $organization->fill([
            'name'       => $data['name'],
            'short_name' => $data['short_name'] ?? null,
            'address'    => $data['address'] ?? null,
            'head_title' => $data['head_title'] ?? null,
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $directory = public_path('uploads/organization');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $name = 'logo-' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($directory, $name);

            if ($organization->logo_path) {
                $old = public_path($organization->logo_path);

                if (is_file($old)) {
                    @unlink($old);
                }
            }

            $organization->logo_path = 'uploads/organization/' . $name;
        }

        $organization->save();

        $after = $organization->only(['name', 'short_name', 'address', 'head_title', 'logo_path']);
        $changes = array_diff_assoc($after, $before);

        if ($changes) {
            ActivityLog::record(
                'Organization',
                $organization->id,
                'update',
                "Updated organization branding for {$organization->name}",
                $changes
            );
        }

        return response()->json($this->payload($organization->fresh()));
    }

    private function payload($organization): array
    {
        return [
            'id'         => $organization->id,
            'name'       => $organization->name,
            'short_name' => $organization->short_name,
            'logo_path'  => $organization->logo_path,
            'logo_url'   => $organization->logoUrl(),
            'address'    => $organization->address,
            'head_title' => $organization->head_title,
        ];
    }
}

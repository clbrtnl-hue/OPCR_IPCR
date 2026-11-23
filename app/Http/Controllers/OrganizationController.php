<?php

namespace App\Http\Controllers;

use App\Support\CurrentOrganization;

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

        return response()->json([
            'id'         => $organization->id,
            'name'       => $organization->name,
            'short_name' => $organization->short_name,
            'logo_path'  => $organization->logo_path,
            'address'    => $organization->address,
            'head_title' => $organization->head_title,
        ]);
    }
}

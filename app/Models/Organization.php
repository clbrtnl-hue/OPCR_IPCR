<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

    protected $guarded = [];

    /** Public URL for the uploaded college logo, or null when none is set. */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        if (str_starts_with($this->logo_path, 'http://') || str_starts_with($this->logo_path, 'https://')) {
            return $this->logo_path;
        }

        return '/' . ltrim($this->logo_path, '/');
    }

    /** Absolute path on disk for DomPDF / Excel letterheads. */
    public function logoFilePath(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        $path = public_path(ltrim($this->logo_path, '/'));

        return is_file($path) ? $path : null;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function orgUnits()
    {
        return $this->hasMany(OrgUnit::class);
    }

    public function schoolYears()
    {
        return $this->hasMany(SchoolYear::class);
    }
}

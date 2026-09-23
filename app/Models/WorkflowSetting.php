<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class WorkflowSetting extends Model
{
    use BelongsToOrganization;

    protected $table = 'workflow_settings';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];
}

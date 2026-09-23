<?php

namespace App\Providers;

use App\Services\WorkflowSettings;
use App\Support\CurrentOrganization;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(WorkflowSettings::class);
    }

    public function boot(): void
    {
        //
    }
}

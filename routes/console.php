<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('vpn:reconcile')->everyMinute()->withoutOverlapping(10);
Schedule::command('vpn:check-servers')->everyFiveMinutes()->withoutOverlapping(4);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

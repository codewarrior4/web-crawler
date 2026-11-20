<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('crawler:schedule')->everyMinute()->withoutOverlapping(5);
Schedule::command('crawler:prune')->dailyAt('03:00')->withoutOverlapping(30);

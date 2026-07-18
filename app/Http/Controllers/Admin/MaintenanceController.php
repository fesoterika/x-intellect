<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __invoke(Request $request)
    {
        $enabled = $request->boolean('enabled');

        Setting::set(Setting::MAINTENANCE, $enabled ? '1' : '0');

        return back()->with('status', $enabled
            ? 'Режим технических работ включён: посетители видят заглушку (503), сайт открыт только редакторам и администраторам.'
            : 'Режим технических работ выключен: сайт снова открыт для посетителей.');
    }
}

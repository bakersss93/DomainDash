<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserSettingsController extends Controller
{
    public function toggleDark(Request $request)
    {
        $user = $request->user();
        $user->dark_mode = ! (bool) $user->dark_mode;
        $user->save();

        return back();
    }
}

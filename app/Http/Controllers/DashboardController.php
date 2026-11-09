<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $children = $user->role === User::GUARDIAN
            ? DB::table('guardian_child_links as links')
                ->join('users', 'users.id', '=', 'links.child_id')
                ->where('links.family_id', $user->family_id)
                ->where('links.guardian_id', $user->id)
                ->where('links.active', true)
                ->where('users.active', true)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name'])
            : [];

        Inertia::encryptHistory();

        return Inertia::render('Dashboard', [
            'name' => $user->name,
            'role' => $user->role,
            'family' => $user->family->name,
            'children' => $children,
        ]);
    }
}

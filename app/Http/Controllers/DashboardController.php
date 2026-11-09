<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
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
            'whatsappNotices' => (bool) $user->whatsapp_notices,
            'earnedPoints' => $user->role === User::CHILD ? (int) DB::table('point_credits')
                ->join('task_occurrences', 'task_occurrences.id', '=', 'point_credits.occurrence_id')
                ->where('task_occurrences.child_id', $user->id)->sum('point_credits.points') : 0,
        ]);
    }

    public function updateNotices(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $user = $request->user();
        User::whereKey($user->id)->where('active', true)->where('session_version', $user->session_version)
            ->update(['whatsapp_notices' => $request->boolean('enabled')]);

        return redirect()->route('dashboard');
    }
}

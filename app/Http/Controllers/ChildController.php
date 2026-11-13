<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveChildRequest;
use App\Services\ChildProfileService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ChildController
{
    public function __construct(private readonly ChildProfileService $profiles) {}

    public function index(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Children', ['children' => $this->profiles->list($request->user())]);
    }

    public function store(SaveChildRequest $request): RedirectResponse
    {
        $guardian = $request->user();
        $destination = $this->destination($request);

        try {
            $this->profiles->register($guardian, $request->validated());
        } catch (UniqueConstraintViolationException) {
            return redirect()->route($destination)->withErrors(['phone' => 'Não foi possível cadastrar esse WhatsApp. Confira o número ou fale com o responsável pela operação do DAG.']);
        }

        return redirect()->route($destination);
    }

    public function edit(Request $request, string $child): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('EditChild', $this->profiles->editData($request->user(), $child));
    }

    public function update(SaveChildRequest $request, string $child): RedirectResponse
    {
        $guardian = $request->user();

        try {
            $this->profiles->update($guardian, $child, $request->validated());
        } catch (UniqueConstraintViolationException) {
            $redirect = $request->query('modal') === '1'
                ? redirect()->route('children.index')
                : redirect()->route('children.edit', $child);

            return $redirect
                ->withErrors(['phone' => 'Esse WhatsApp já está em uso. Informe outro número.']);
        }

        return redirect()->route($this->destination($request));
    }

    private function destination(Request $request): string
    {
        return $request->query('from') === 'children' ? 'children.index' : 'dashboard';
    }
}

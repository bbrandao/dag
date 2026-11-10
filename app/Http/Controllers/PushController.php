<?php

namespace App\Http\Controllers;

use App\Repositories\PushRepository;
use App\Services\PushTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class PushController
{
    public function __construct(private readonly PushRepository $push, private readonly PushTransport $transport) {}

    public function status(Request $request): JsonResponse
    {
        $hash = $this->endpointHash($request);
        $enabled = $this->transport->configured() && $this->push->ownsSubscription($request->user(), $hash);
        if ($enabled) {
            $request->session()->put('push_endpoint_hash', $hash);
        }

        return response()->json(['enabled' => $enabled]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->transport->configured(), 503);
        $hash = $this->endpointHash($request);
        $data = $request->validate([
            'keys.p256dh' => ['required', 'string', 'regex:/^B[A-Za-z0-9_-]{86}$/D'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}$/D'],
        ]);
        $subscription = ['endpoint' => $request->input('endpoint'), 'keys' => $data['keys'], 'contentEncoding' => 'aes128gcm'];
        $this->push->subscribe($request->user(), $hash, Crypt::encryptString(json_encode($subscription, JSON_THROW_ON_ERROR)));
        $request->session()->put('push_endpoint_hash', $hash);

        return response()->json(['enabled' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $hash = $this->endpointHash($request);
        $this->push->unsubscribe($request->user()->id, $hash);
        if ($request->session()->get('push_endpoint_hash') === $hash) {
            $request->session()->forget('push_endpoint_hash');
        }

        return response()->json(['enabled' => false]);
    }

    private function endpointHash(Request $request): string
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'url:https', 'max:2048']]);
        if (! $this->transport->acceptsEndpoint($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'Este serviço de notificações não é compatível com o DAG.']);
        }

        return hash('sha256', $data['endpoint']);
    }
}

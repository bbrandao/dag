<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

final class GeneratePushKeys extends Command
{
    protected $signature = 'dag:push-keys';

    protected $description = 'Gera um par de chaves VAPID para as notificações do DAG';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('DAG_PUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('DAG_PUSH_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}

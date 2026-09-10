<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GeneratePushKeys extends Command
{
    protected $signature = 'familyhub:push-keys';

    protected $description = 'Generate a VAPID key pair for Web Push';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->components->info('Add these to .env, then redeploy:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@example.com');
        $this->newLine();
        $this->components->warn('The private key signs every push. Keep it out of version control.');

        return self::SUCCESS;
    }
}

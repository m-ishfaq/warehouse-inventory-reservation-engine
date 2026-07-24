<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate the bearer token that guards the mutating API endpoints.
 *
 * Uses random_bytes (CSPRNG), not Str::random or rand — a predictable API token
 * is the same as no API token, and the difference is invisible until somebody
 * guesses one.
 */
class GenerateApiTokenCommand extends Command
{
    protected $signature = 'inventory:token
                            {--set : Write the token into .env instead of only printing it}
                            {--bytes=32 : Entropy in bytes (64 hex characters by default)}';

    protected $description = 'Generate a bearer token for the inventory API';

    public function handle(): int
    {
        $bytes = max(16, (int) $this->option('bytes'));
        $token = bin2hex(random_bytes($bytes));

        if (! $this->option('set')) {
            $this->newLine();
            $this->line('  <options=bold>'.$token.'</>');
            $this->newLine();
            $this->line('  Add to .env:  <fg=yellow>INVENTORY_API_TOKEN='.$token.'</>');
            $this->line('  Or write it automatically:  <fg=yellow>php artisan inventory:token --set</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $path = base_path('.env');

        if (! is_file($path)) {
            $this->error('No .env file found. Copy .env.example to .env first.');

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        $replaced = preg_replace(
            '/^INVENTORY_API_TOKEN=.*$/m',
            'INVENTORY_API_TOKEN='.$token,
            $contents,
            limit: 1,
            count: $count,
        );

        // Append rather than fail when the key is absent — a .env copied from an
        // older revision should still work.
        if ($count === 0) {
            $replaced = rtrim($contents, "\r\n")."\n\nINVENTORY_API_TOKEN=".$token."\n";
        }

        file_put_contents($path, $replaced);

        $this->info('INVENTORY_API_TOKEN written to .env.');
        $this->line('  <options=bold>'.$token.'</>');
        $this->newLine();
        $this->line('  <fg=gray>Run `php artisan config:clear` if you have cached config.</>');

        return self::SUCCESS;
    }
}

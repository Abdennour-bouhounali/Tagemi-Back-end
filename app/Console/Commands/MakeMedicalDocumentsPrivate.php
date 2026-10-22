<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off migration: moves medical documents uploaded before the security
 * fix from the public disk (reachable at /storage/...) to the private disk.
 * Stored paths don't change, so no database update is needed.
 */
class MakeMedicalDocumentsPrivate extends Command
{
    protected $signature = 'medical-documents:make-private {--dry-run : Only list what would be moved}';

    protected $description = 'Move medical documents from the public disk to the private disk';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(Appointment::DOCUMENTS_DISK);
        $moved = 0;

        foreach ($public->allFiles('medical-documents') as $path) {
            if (basename($path) === '.gitkeep') {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would move: {$path}");
                $moved++;
                continue;
            }

            $stream = $public->readStream($path);
            $private->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($private->exists($path) && $private->size($path) === $public->size($path)) {
                $public->delete($path);
                $moved++;
            } else {
                $this->error("failed to copy: {$path}");
            }
        }

        $this->info(($this->option('dry-run') ? 'Would move' : 'Moved') . " {$moved} file(s).");

        return self::SUCCESS;
    }
}

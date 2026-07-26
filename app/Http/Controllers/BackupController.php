<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\Process\Process;

class BackupController extends Controller
{
    private string $dir = 'backups';

    public function index()
    {
        Storage::disk('local')->makeDirectory($this->dir);
        $files = collect(Storage::disk('local')->files($this->dir))
            ->filter(fn ($f) => str_ends_with($f, '.sql'))
            ->map(fn ($f) => [
                'name' => basename($f),
                'size' => round(Storage::disk('local')->size($f) / 1024, 1).' KB',
                'at' => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($f))->format('Y-m-d H:i'),
            ])
            ->sortByDesc('name')->values();

        return Inertia::render('Backup/Index', ['backups' => $files]);
    }

    public function create(\Illuminate\Http\Request $request)
    {
        $db = config('database.connections.'.config('database.default'));
        $filename = 'backup-'.now()->format('Ymd-His').'.sql';
        $path = Storage::disk('local')->path($this->dir.'/'.$filename);
        Storage::disk('local')->makeDirectory($this->dir);

        $args = ['mysqldump', '-h', (string) $db['host'], '-P', (string) $db['port'], '-u', (string) $db['username']];
        if (! empty($db['password'])) {
            $args[] = '-p'.$db['password'];
        }
        $args[] = $db['database'];

        $process = new Process($args);
        $process->setTimeout(120);
        $out = fopen($path, 'w');
        $process->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT) {
                fwrite($out, $buffer);
            }
        });
        fclose($out);

        if (! $process->isSuccessful()) {
            @unlink($path);

            return back()->withErrors(['backup' => 'Backup failed: '.trim($process->getErrorOutput() ?: 'mysqldump not available')]);
        }

        return back()->with('success', "Backup created: {$filename}");
    }

    public function download(string $name)
    {
        $path = $this->dir.'/'.basename($name);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $name)
    {
        Storage::disk('local')->delete($this->dir.'/'.basename($name));

        return back()->with('success', 'Backup deleted.');
    }
}

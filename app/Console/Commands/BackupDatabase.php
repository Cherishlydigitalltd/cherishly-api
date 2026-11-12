<?php

namespace App\Console\Commands;

use App\Support\Backup\PhpPostgresDumper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Nightly copy of the whole database, saved as a gzipped .sql file in
 * storage/app/backups (outside the public folder). Keeps BACKUP_KEEP_DAYS
 * days of files and emails ALERT_EMAIL if a backup fails.
 *
 * Uses pg_dump when the server allows it; on shared hosting where it's
 * missing or blocked, falls back to a dump written in PHP. Either file
 * restores into an empty database (cPanel → phpPgAdmin → SQL, or psql).
 *
 * It's scheduled hourly overnight and skips once today's backup exists, so a
 * missed cron ping doesn't mean a missed backup. --force makes another one.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--force : Make a backup even if today\'s already exists}';

    protected $description = 'Back up the database to storage/app/backups';

    public function handle(): int
    {
        @set_time_limit(600);

        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return $this->failed("Couldn't create the backup folder {$dir}.");
        }
        // storage/ isn't web-reachable, but deny it anyway in case it ever is.
        if (!is_file("$dir/.htaccess")) {
            @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
        }

        if (!$this->option('force') && glob($dir . '/cherishly-' . now()->format('Y-m-d') . '-*.sql.gz')) {
            $this->info('Today\'s backup already exists.');
            return self::SUCCESS;
        }

        $file = $dir . '/cherishly-' . now()->format('Y-m-d-His') . '.sql.gz';
        $part = $file . '.part';

        try {
            $method = $this->dump($part);

            // A dump this small means something went wrong half-way.
            clearstatcache();
            if (!is_file($part) || filesize($part) < 512) {
                throw new \RuntimeException('The backup file came out empty.');
            }
            rename($part, $file);
        } catch (Throwable $e) {
            @unlink($part);
            return $this->failed($e->getMessage());
        }

        $size = round(filesize($file) / 1024 / 1024, 2);
        $removed = $this->prune($dir);

        $msg = "Backup saved: " . basename($file) . " ({$size} MB, {$method})" . ($removed ? ", removed {$removed} old" : '');
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    /** Write the dump to $path; returns which method worked. */
    private function dump(string $path): string
    {
        $db = config('database.connections.' . config('database.default'));
        if (($db['driver'] ?? null) !== 'pgsql') {
            throw new \RuntimeException('Backups only support PostgreSQL.');
        }

        $pgDumpError = $this->tryPgDump($db, $path);
        if ($pgDumpError === null) {
            return 'pg_dump';
        }
        Log::info('db:backup: pg_dump not usable (' . $pgDumpError . '), using the PHP dumper.');

        $gz = gzopen($path, 'wb6');
        if (!$gz) {
            throw new \RuntimeException("Couldn't write {$path}.");
        }
        try {
            (new PhpPostgresDumper(DB::connection()))->dump(fn (string $sql) => gzwrite($gz, $sql));
        } finally {
            gzclose($gz);
        }

        return 'php';
    }

    /** Null when pg_dump wrote the file; otherwise why it couldn't. */
    private function tryPgDump(array $db, string $path): ?string
    {
        if (!function_exists('proc_open')) {
            return 'proc_open is disabled';
        }
        $bin = config('backup.pg_dump') ?: (new ExecutableFinder)->find('pg_dump', null, ['/usr/bin', '/usr/local/bin', '/usr/pgsql/bin']);
        if (!$bin) {
            return 'pg_dump not found';
        }

        $gz = gzopen($path, 'wb6');
        if (!$gz) {
            return "couldn't write the file";
        }

        try {
            $process = new Process(
                [$bin, '--no-owner', '--no-privileges', '--clean', '--if-exists',
                 '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username'], (string) $db['database']],
                null,
                ['PGPASSWORD' => (string) $db['password'], 'PGCONNECT_TIMEOUT' => '15'],
                null,
                540,
            );
            $errors = '';
            $process->run(function ($type, $chunk) use ($gz, &$errors) {
                $type === Process::OUT ? gzwrite($gz, $chunk) : $errors .= $chunk;
            });
        } catch (Throwable $e) {
            gzclose($gz);
            return $e->getMessage();
        }
        gzclose($gz);

        if (!$process->isSuccessful()) {
            // e.g. "server version mismatch" when the host's pg_dump is older.
            return trim(mb_strimwidth($errors, 0, 300)) ?: 'exit code ' . $process->getExitCode();
        }

        return null;
    }

    /** Delete backups older than BACKUP_KEEP_DAYS, always keeping the newest 3. */
    private function prune(string $dir): int
    {
        $files = glob($dir . '/cherishly-*.sql.gz') ?: [];
        rsort($files); // names sort by date, newest first
        $cutoff = now()->subDays((int) config('backup.keep_days', 14))->getTimestamp();

        $removed = 0;
        foreach (array_slice($files, 3) as $f) {
            if (filemtime($f) < $cutoff && @unlink($f)) {
                $removed++;
            }
        }
        // Leftovers from a run that died mid-way.
        foreach (glob($dir . '/*.part') ?: [] as $f) {
            if (filemtime($f) < now()->subDay()->getTimestamp()) {
                @unlink($f);
            }
        }

        return $removed;
    }

    private function failed(string $why): int
    {
        Log::error('Database backup failed: ' . $why);
        $this->error('Backup failed: ' . $why);

        // It retries hourly overnight; one email a day is enough.
        $to = config('app.alert_email');
        if ($to && Cache::add('backup-failed-alert:' . now()->format('Y-m-d'), 1, now()->addDay())) {
            try {
                Mail::raw(
                    "Last night's database backup failed.\n\n"
                    . "Reason: {$why}\n"
                    . 'Time: ' . now()->toDayDateTimeString() . " (UTC)\n\n"
                    . "It will try again in an hour. If this keeps happening, take a manual backup "
                    . "from cPanel (phpPgAdmin → Export) until it's fixed.",
                    fn ($m) => $m->to($to)->subject('[Cherishly API] Database backup failed'),
                );
            } catch (Throwable) {
                // Already logged.
            }
        }

        return self::FAILURE;
    }
}

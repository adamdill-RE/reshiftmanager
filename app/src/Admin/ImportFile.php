<?php

declare(strict_types=1);

namespace Resm\Admin;

use Resm\Session;

/**
 * The uploaded CSV, held between the dry run and the confirm.
 *
 * The two are separate requests, and the parsed plan cannot travel between
 * them: a session is a file with a 1440-second gc_maxlifetime this application
 * does not control (CLAUDE.md), and an Admin reading a 150-row summary can
 * easily take longer than that. So the FILE is kept and re-parsed on confirm,
 * which is also the stronger arrangement — there is no way for the summary
 * that was approved to describe one thing while the write does another.
 *
 * The token lives in the session and the file is named after it, so one
 * administrator cannot confirm another's upload. The digest is checked on the
 * way back out: if the bytes are not the ones the dry run read, the confirm
 * refuses rather than importing something nobody reviewed.
 */
final class ImportFile
{
    private const TOKEN = 'import_token';
    private const NAME = 'import_name';
    private const DIGEST = 'import_digest';

    /** Long enough for an Admin to read a summary, short enough to be tidy. */
    private const KEEP_SECONDS = 7200;

    public function __construct(private string $directory)
    {
    }

    /** @return array{ok: bool, error: ?string} */
    public function store(string $content, string $originalName): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return ['ok' => false, 'error' => 'The server has nowhere to put the upload.'];
        }

        $this->sweep();
        $this->discard();

        $token = bin2hex(random_bytes(16));
        if (@file_put_contents($this->path($token), $content) === false) {
            return ['ok' => false, 'error' => 'The upload could not be saved.'];
        }
        @chmod($this->path($token), 0600);

        Session::set(self::TOKEN, $token);
        Session::set(self::NAME, mb_substr($originalName, 0, 120));
        Session::set(self::DIGEST, hash('sha256', $content));

        return ['ok' => true, 'error' => null];
    }

    /** The bytes the dry run read, or null if there is nothing to confirm. */
    public function contents(): ?string
    {
        $token = Session::get(self::TOKEN);
        if (!is_string($token) || !self::isToken($token)) {
            return null;
        }

        $path = $this->path($token);
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        // The file is named after a session-held random token, so this should
        // never differ. It is checked anyway: confirming an import nobody
        // reviewed is exactly the failure this screen exists to prevent.
        $digest = Session::get(self::DIGEST);
        if (!is_string($digest) || !hash_equals($digest, hash('sha256', $content))) {
            return null;
        }

        return $content;
    }

    public function name(): ?string
    {
        $name = Session::get(self::NAME);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function isPending(): bool
    {
        return $this->contents() !== null;
    }

    public function discard(): void
    {
        $token = Session::get(self::TOKEN);
        if (is_string($token) && self::isToken($token) && is_file($this->path($token))) {
            @unlink($this->path($token));
        }

        Session::forget(self::TOKEN);
        Session::forget(self::NAME);
        Session::forget(self::DIGEST);
    }

    /**
     * Remove uploads nobody came back for.
     *
     * There is no cron on this account, so this runs on the way in. It is a
     * directory listing of a handful of files a couple of times a season.
     */
    public function sweep(): void
    {
        $cutoff = time() - self::KEEP_SECONDS;

        foreach (glob($this->directory . '/*.csv') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function path(string $token): string
    {
        return $this->directory . '/' . $token . '.csv';
    }

    /** Belt and braces: the token names a file, so it may only be hex. */
    private static function isToken(string $token): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $token) === 1;
    }
}

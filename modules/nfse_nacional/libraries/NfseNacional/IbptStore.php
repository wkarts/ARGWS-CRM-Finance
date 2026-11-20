<?php
declare(strict_types=1);
namespace NfseNacional;

/** Bounded, company-scoped IBPT cache. The lock also serializes a remote refresh. */
final class IbptStore
{
    private const HEADER = "<?php exit; ?>\n";
    private const MAX_BYTES = 2097152;
    private string $directory;
    private \Closure $clock;

    public function __construct(string $directory, string $scope, ?callable $clock = null)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $scope) || $directory === '' || str_contains($directory, "\0")
            || str_contains($directory, '://') || str_starts_with($directory, '//') || str_starts_with($directory, '\\\\')
            || !preg_match('~\A(?:/|[A-Za-z]:[\\\\/])~', $directory)) {
            throw new \InvalidArgumentException('Contexto da tabela IBPT inválido.');
        }
        $root = rtrim($directory, '/\\');
        $this->directory = $root . '/' . $scope;
        if (is_link($root) || is_link($this->directory)
            || (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))) {
            throw new \RuntimeException('Não foi possível preparar a consulta de tributos aproximados.');
        }
        @chmod($this->directory, 0700);
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): int => time();
    }

    /** A local snapshot never creates a lock, rewrites a table or performs a remote request. */
    public function inspect(string $uf, callable $operation): mixed
    {
        $file = $this->file($uf);
        return $operation($this->read($file), ($this->clock)());
    }

    /** The callback may persist a cooldown even when it raises a user-readable failure. */
    public function transaction(string $uf, callable $operation): mixed
    {
        $file = $this->file($uf);
        $lockFile = $this->directory . '/lock-' . $uf . '.php';
        if (is_link($file) || is_link($lockFile)) { throw new \RuntimeException('Armazenamento da tabela IBPT indisponível.'); }
        $lock = @fopen($lockFile, 'c+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new \RuntimeException('A tabela IBPT está sendo atualizada. Tente novamente em instantes.', 429);
        }
        @chmod($lockFile, 0600);
        try {
            $stat = fstat($lock);
            if (!$stat || (($stat['mode'] & 0170000) !== 0100000)) { throw new \RuntimeException('Armazenamento da tabela IBPT indisponível.'); }
            if ($stat['size'] === 0 && (fwrite($lock, self::HEADER) !== strlen(self::HEADER) || !fflush($lock))) {
                throw new \RuntimeException('Não foi possível registrar a consulta de tributos.');
            }
            $state = $this->read($file);
            $original = $state;
            $failure = null; $result = null;
            try { $result = $operation($state, ($this->clock)()); }
            catch (\Throwable $error) { $failure = $error; }
            if ($state !== $original) { $this->write($file, $state); }
            if ($failure !== null) { throw $failure; }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function file(string $uf): string
    {
        if (!in_array($uf, explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'), true)) {
            throw new \InvalidArgumentException('UF do emitente inválida para a consulta de tributos.');
        }
        $file = $this->directory . '/table-' . $uf . '.php';
        if (is_link($this->directory) || is_link($file)) { throw new \RuntimeException('Armazenamento da tabela IBPT indisponível.'); }
        return $file;
    }

    private function read(string $file): array
    {
        if (!is_file($file)) { return []; }
        $stream = @fopen($file, 'rb');
        if (!$stream) { throw new \RuntimeException('Não foi possível ler a tabela IBPT armazenada.'); }
        try {
            $stat = fstat($stream);
            if (!$stat || (($stat['mode'] & 0170000) !== 0100000) || $stat['size'] > self::MAX_BYTES) {
                throw new \RuntimeException('Armazenamento da tabela IBPT inválido.');
            }
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally { fclose($stream); }
        if (!is_string($bytes) || !str_starts_with($bytes, self::HEADER)) { return []; }
        $state = json_decode(substr($bytes, strlen(self::HEADER)), true, 16);
        return is_array($state) ? $state : [];
    }

    private function write(string $file, array $state): void
    {
        $encoded = self::HEADER . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_BYTES) { throw new \RuntimeException('A tabela IBPT excede o limite de armazenamento.'); }
        $temporary = $this->directory . '/temporary-' . bin2hex(random_bytes(12)) . '.php';
        $stream = @fopen($temporary, 'x+b');
        if (!$stream) { throw new \RuntimeException('Não foi possível armazenar a tabela IBPT.'); }
        @chmod($temporary, 0600);
        try {
            $length = strlen($encoded); $written = 0;
            while ($written < $length) {
                $part = fwrite($stream, substr($encoded, $written));
                if ($part === false || $part === 0) { throw new \RuntimeException('Não foi possível armazenar a tabela IBPT.'); }
                $written += $part;
            }
            if (!fflush($stream)) { throw new \RuntimeException('Não foi possível armazenar a tabela IBPT.'); }
            fclose($stream); $stream = null;
            if (is_link($file) || !@rename($temporary, $file)) { throw new \RuntimeException('Não foi possível concluir a atualização da tabela IBPT.'); }
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }
}

<?php
declare(strict_types=1);
namespace NfseNacional;

/** Small company-scoped cache and atomic request budget in the CRM cache directory. */
final class RegistrationLookupStore
{
    private string $file;
    private \Closure $clock;

    public function __construct(string $directory, string $scope, ?callable $clock = null)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $scope) || $directory === '' || str_contains($directory, "\0")) {
            throw new \InvalidArgumentException('Contexto de consulta inválido.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar a consulta. Tente novamente.');
        }
        $this->file = rtrim($directory, '/\\') . '/lookup-' . $scope . '.php';
        if (is_link($this->file)) { throw new \RuntimeException('Armazenamento de consulta indisponível.'); }
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): int => time();
    }

    public function get(string $key): ?array
    {
        return $this->locked(static fn(array &$state, int $now): ?array => $state['cache'][$key]['data'] ?? null);
    }

    public function put(string $key, array $data, int $ttl): void
    {
        $this->locked(static function (array &$state, int $now) use ($key, $data, $ttl): void {
            $state['cache'][$key] = ['expires' => $now + max(1, min(21600, $ttl)), 'data' => $data];
            if (count($state['cache']) > 128) {
                uasort($state['cache'], static fn(array $a, array $b): int => $a['expires'] <=> $b['expires']);
                $state['cache'] = array_slice($state['cache'], -128, null, true);
            }
        });
    }

    public function hit(string $key, int $limit, int $window): void
    {
        $this->locked(static function (array &$state, int $now) use ($key, $limit, $window): void {
            $budget = $state['rates'][$key] ?? ['expires' => $now + $window, 'count' => 0];
            if ($budget['count'] >= $limit) {
                throw new \RuntimeException('Aguarde um minuto antes de realizar novas consultas.', 429);
            }
            ++$budget['count'];
            $state['rates'][$key] = $budget;
        });
    }

    private function locked(callable $operation): mixed
    {
        $stream = @fopen($this->file, 'c+b');
        if (!$stream || !flock($stream, LOCK_EX | LOCK_NB)) {
            if (is_resource($stream)) { fclose($stream); }
            throw new \RuntimeException('Já existe uma consulta em andamento. Tente novamente.', 429);
        }
        @chmod($this->file, 0600);
        try {
            $bytes = stream_get_contents($stream, 1048577);
            if (strlen($bytes) > 1048576) { throw new \RuntimeException('Armazenamento de consulta indisponível.'); }
            $prefix = "<?php exit; ?>\n";
            $state = str_starts_with($bytes, $prefix) ? json_decode(substr($bytes, strlen($prefix)), true) : null;
            $state = is_array($state) ? $state : [];
            $now = ($this->clock)();
            foreach (['cache', 'rates'] as $kind) {
                $state[$kind] = array_filter(is_array($state[$kind] ?? null) ? $state[$kind] : [],
                    static fn($entry): bool => is_array($entry) && (int) ($entry['expires'] ?? 0) > $now);
            }
            $result = $operation($state, $now);
            $encoded = $prefix . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            rewind($stream);
            if (!ftruncate($stream, 0) || fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream)) {
                throw new \RuntimeException('Não foi possível concluir a consulta. Tente novamente.');
            }
            return $result;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
}

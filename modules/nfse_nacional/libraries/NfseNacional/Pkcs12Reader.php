<?php
declare(strict_types=1);

namespace NfseNacional;

/**
 * Compatibility reader for PKCS#12 algorithms unavailable to the PHP provider.
 * The legacy provider lives only in a short-lived OpenSSL child process.
 */
final class Pkcs12Reader
{
    private const MAX_INPUT_BYTES = 4194304;
    private const MAX_OUTPUT_BYTES = 8388608;
    private const MAX_ERROR_BYTES = 65536;
    private const MAX_ITERATIONS = 1000000;
    private const TIMEOUT_SECONDS = 8.0;
    private const BINARIES = [
        '/usr/bin/openssl', '/usr/local/bin/openssl', '/bin/openssl',
        '/opt/homebrew/opt/openssl@3/bin/openssl',
    ];

    /** @return array{cert:string,pkey:string,extracerts:array} */
    public static function read(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $password): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException('O certificado A1 deve ter entre 1 byte e 4 MB.');
        }
        // OpenSSL fd passphrases are line-oriented and use a bounded password buffer.
        if (strlen($password) > 1023 || strpbrk($password, "\0\r\n") !== false) {
            throw new \InvalidArgumentException('Esta senha não pôde ser usada na leitura adicional do A1. Utilize uma exportação atual do certificado.');
        }
        $binary = self::binary();
        if ($binary === null) {
            throw new \RuntimeException('Este arquivo A1 não pôde ser aberto com os recursos disponíveis no servidor. Confira a senha ou utilize uma exportação atual do certificado.');
        }

        // No shell, password argument, password environment variable or temporary file.
        $command = [$binary, 'pkcs12', '-legacy', '-nodes', '-info', '-passin', 'fd:3'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null,
            ['OPENSSL_CONF' => '/dev/null', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8'], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Não foi possível iniciar a leitura adicional do certificado A1.');
        }
        try {
            foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
            [$output, $information, $exit] = self::exchange($process, $pipes, $bytes, $password);
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $status = proc_get_status($process);
            if ($status['running']) { proc_terminate($process, 9); }
            $closedExit = proc_close($process);
        }
        if ($exit < 0 && $closedExit >= 0) { $exit = $closedExit; }
        if ($exit !== 0) { throw self::invalid(); }
        // Verification is enabled by default. A PFX without a MAC must not be
        // accepted by the compatibility path merely because its bags decrypt.
        if (!preg_match('/(?:\A|\n)MAC:\s*[^\r\n,]+,\s*Iteration\s+[0-9]+(?:\r?\n|\z)/', $information)) {
            throw new \InvalidArgumentException('Não foi possível conferir a integridade do arquivo A1. Reexporte o certificado e tente novamente.');
        }
        return self::extractParts($output);
    }

    private static function binary(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows' || !is_readable('/dev/null')
            || !function_exists('proc_open') || !function_exists('proc_get_status')
            || !function_exists('proc_terminate') || !function_exists('proc_close')
            || !function_exists('stream_select')) {
            return null;
        }
        foreach (self::BINARIES as $candidate) {
            $path = realpath($candidate);
            $permissions = $path !== false ? @fileperms($path) : false;
            if ($path !== false && is_file($path) && is_executable($path)
                && $permissions !== false && ($permissions & 0002) === 0) {
                return $path;
            }
        }
        return null;
    }

    /** Drain both outputs while feeding separate, nonblocking input pipes. */
    private static function exchange($process, array &$pipes, #[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $password): array
    {
        $inputs = [0 => $bytes, 3 => $password . "\n"];
        $positions = [0 => 0, 3 => 0];
        $output = ''; $information = ''; $exit = -1;
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (true) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('A leitura do certificado A1 excedeu o tempo permitido.');
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                if ($status['exitcode'] >= 0) { $exit = (int) $status['exitcode']; }
                foreach ([0, 3] as $index) { if (isset($pipes[$index])) { fclose($pipes[$index]); unset($pipes[$index]); } }
            }
            $read = []; $write = []; $except = null;
            foreach ([1, 2] as $index) {
                if (!isset($pipes[$index])) { continue; }
                if (feof($pipes[$index])) { fclose($pipes[$index]); unset($pipes[$index]); }
                else { $read[] = $pipes[$index]; }
            }
            foreach ([0, 3] as $index) { if (isset($pipes[$index])) { $write[] = $pipes[$index]; } }
            if (!$read && !$write) {
                if (!$status['running']) { break; }
                usleep(1000);
                continue;
            }
            $ready = @stream_select($read, $write, $except, 0, 100000);
            if ($ready === false) { throw new \RuntimeException('Não foi possível concluir a leitura do certificado A1.'); }
            foreach ($write as $stream) {
                $index = array_search($stream, $pipes, true);
                if (!isset($inputs[$index])) { continue; }
                $count = @fwrite($stream, substr($inputs[$index], $positions[$index], 8192));
                if ($count === false) { throw self::invalid(); }
                $positions[$index] += $count;
                if ($positions[$index] >= strlen($inputs[$index])) { fclose($pipes[$index]); unset($pipes[$index]); unset($inputs[$index]); }
            }
            foreach ($read as $stream) {
                $index = array_search($stream, $pipes, true);
                $chunk = @fread($stream, 65536);
                if ($chunk === false) { throw self::invalid(); }
                if ($index === 1) { $output .= $chunk; }
                elseif ($index === 2) { $information .= $chunk; }
            }
            if (strlen($output) > self::MAX_OUTPUT_BYTES || strlen($information) > self::MAX_ERROR_BYTES) {
                throw new \InvalidArgumentException('O conteúdo do certificado A1 excede o limite permitido.');
            }
            if (preg_match_all('/\bIteration\s+([0-9]+)/', $information, $matches)) {
                foreach ($matches[1] as $iterations) {
                    if (strlen($iterations) > 7 || (int) $iterations > self::MAX_ITERATIONS) {
                        throw new \InvalidArgumentException('O certificado A1 exige parâmetros de leitura acima do limite permitido.');
                    }
                }
            }
        }
        return [$output, $information, $exit];
    }

    private static function extractParts(#[\SensitiveParameter] string $output): array
    {
        preg_match_all('/-----BEGIN ((?:RSA |EC |DSA )?PRIVATE KEY|CERTIFICATE)-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END \1-----/', $output, $blocks, PREG_SET_ORDER);
        $keys = []; $certificates = [];
        foreach ($blocks as $block) {
            if ($block[1] === 'CERTIFICATE') { $certificates[] = $block[0] . "\n"; }
            else { $keys[] = $block[0] . "\n"; }
        }
        if (count($keys) !== 1) {
            throw new \InvalidArgumentException('Importe um arquivo A1 com uma única chave privada do emitente.');
        }
        $matching = []; $extra = [];
        foreach (array_unique($certificates) as $certificate) {
            if (@openssl_x509_check_private_key($certificate, $keys[0])) { $matching[] = $certificate; }
            else { $extra[] = $certificate; }
        }
        while (openssl_error_string() !== false) {}
        if (count($matching) !== 1) {
            throw new \InvalidArgumentException('O A1 deve conter um único certificado correspondente à chave privada do emitente.');
        }
        return ['cert' => $matching[0], 'pkey' => $keys[0], 'extracerts' => $extra];
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Não foi possível abrir o A1. Confira o arquivo e a senha.');
    }
}

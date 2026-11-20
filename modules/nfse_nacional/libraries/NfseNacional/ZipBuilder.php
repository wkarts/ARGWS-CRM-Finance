<?php
declare(strict_types=1);
namespace NfseNacional;

/** Bounded ZIP writer with private temporary storage and UTF-8 names. No ZIP extension is required. */
final class ZipBuilder
{
    private $stream;
    private array $central = [];
    private array $names = [];
    private int $offset = 0;
    private int $uncompressed = 0;

    public function __construct(private int $maxUncompressed = 104857600)
    {
        $this->stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($this->stream === false) { throw new \RuntimeException('Não foi possível preparar o pacote fiscal.'); }
    }

    public function __destruct() { if (is_resource($this->stream)) { fclose($this->stream); } }

    public function add(string $path, string $bytes): void
    {
        if ($path === '' || strlen($path) > 512 || !preg_match('//u', $path) || preg_match('~(?:^/|\\\\|[\x00-\x1f\x7f]|(?:^|/)\.\.?(/|$))~', $path)
            || str_contains($path, ':') || str_ends_with($path, '/') || isset($this->names[$path])) {
            throw new \InvalidArgumentException('Nome de arquivo inválido ou repetido no pacote fiscal.');
        }
        if (count($this->names) >= 65534 || strlen($bytes) > $this->maxUncompressed - $this->uncompressed) {
            throw new \RuntimeException('O pacote excede o limite de 100 MB descompactado. Reduza o período ou a seleção.');
        }
        $compressed = gzdeflate($bytes, 6);
        if ($compressed === false) { throw new \RuntimeException('Não foi possível compactar o pacote fiscal.'); }
        $method = strlen($compressed) < strlen($bytes) ? 8 : 0;
        $payload = $method === 8 ? $compressed : $bytes;
        $crc = crc32($bytes); $flags = 0x0800; $date = 0x0021; $size = strlen($bytes); $packed = strlen($payload);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, 0, $date, $crc, $packed, $size, strlen($path), 0) . $path;
        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0314, 20, $flags, $method, 0, $date,
            $crc, $packed, $size, strlen($path), 0, 0, 0, 0, 0x81A40000, $this->offset) . $path;
        $this->write($header); $this->write($payload);
        $this->offset += strlen($header) + $packed; $this->uncompressed += $size; $this->names[$path] = true;
    }

    public function finish(): string
    {
        $directory = implode('', $this->central); $count = count($this->central);
        fseek($this->stream, $this->offset);
        $this->write($directory); $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($directory), $this->offset, 0));
        rewind($this->stream); $bytes = stream_get_contents($this->stream);
        if (!is_string($bytes)) { throw new \RuntimeException('Não foi possível concluir o pacote fiscal.'); }
        return $bytes;
    }

    public function uncompressedSize(): int { return $this->uncompressed; }

    private function write(string $bytes): void
    {
        $offset = 0; $size = strlen($bytes);
        while ($offset < $size) {
            $written = fwrite($this->stream, substr($bytes, $offset, min(1048576, $size - $offset)));
            if ($written === false || $written === 0) { throw new \RuntimeException('Não foi possível gravar o pacote fiscal temporário.'); }
            $offset += $written;
        }
    }
}

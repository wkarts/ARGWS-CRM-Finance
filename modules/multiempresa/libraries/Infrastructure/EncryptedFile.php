<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Domain\Failure;

final class EncryptedFile
{
    public function __construct(private Vault $vault)
    {
    }
    public function encrypt(string $source, string $dest): void
    {
        $key = hex2bin($this->vault->mac('pme:backup:v1'));
        [$state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $in = fopen($source, 'rb');
        $out = fopen($dest.'.tmp', 'xb');
        if (!$in || !$out) {
            throw new Failure('backup_io', 'Não foi possível abrir o arquivo de backup.');
        }
        try {
            fwrite($out, 'PME1'.$header);
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new Failure('backup_read', 'Falha ao ler backup.');
                }
                $tag = feof($in) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0;
                $data = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                if (fwrite($out, pack('N', strlen($data)).$data) !== strlen($data) + 4) {
                    throw new Failure('backup_write', 'Falha ao gravar backup.');
                }
            }
            fflush($out);
            if (function_exists('fsync')) {
                fsync($out);
            }
            fclose($out);
            $out = null;
            chmod($dest.'.tmp', 0600);
            if (!rename($dest.'.tmp', $dest)) {
                throw new Failure('backup_rename', 'Falha ao concluir backup.');
            }
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }@unlink($dest.'.tmp');
        }
    }
    public function decrypt(string $source, string $dest): void
    {
        $in = fopen($source, 'rb');
        $tmp = $dest.'.'.bin2hex(random_bytes(4)).'.tmp';
        $out = fopen($tmp, 'xb');
        if (!$in || !$out) {
            throw new Failure('backup_io', 'Falha ao abrir backup.');
        }
        try {
            if (fread($in, 4) !== 'PME1') {
                throw new Failure('backup_format', 'Formato inválido.');
            }
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, hex2bin($this->vault->mac('pme:backup:v1')));
            $final = false;
            while (!feof($in)) {
                $len = fread($in, 4);
                if ($len === '') {
                    break;
                }
                if (strlen($len) !== 4 || $final) {
                    throw new Failure('backup_integrity', 'Backup truncado ou com conteúdo adicional.');
                }
                $size = unpack('N', $len)[1];
                if ($size < 17 || $size > 65553) {
                    throw new Failure('backup_integrity', 'Bloco inválido.');
                }
                $data = '';
                while (strlen($data) < $size && !feof($in)) {
                    $data .= fread($in, $size - strlen($data));
                }
                $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $data);
                if ($r === false) {
                    throw new Failure('backup_integrity', 'Autenticidade do backup inválida.');
                }[$plain,$tag] = $r;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                if (fwrite($out, $plain) !== strlen($plain)) {
                    throw new Failure('backup_write', 'Falha ao restaurar arquivo.');
                }
            }
            if (!$final) {
                throw new Failure('backup_integrity', 'Backup incompleto.');
            }
            fclose($out);
            $out = null;
            chmod($tmp, 0600);
            if (!rename($tmp, $dest)) {
                throw new Failure('backup_rename', 'Falha ao concluir restauração.');
            }
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }@unlink($tmp);
        }
    }
}

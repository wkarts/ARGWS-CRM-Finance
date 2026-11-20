<?php
declare(strict_types=1);

namespace NfseNacional;

/** A transport failure must never be treated as a fiscal rejection. */
final class ApiException extends \RuntimeException
{
    private int $status;
    private bool $uncertain;
    private array $messages;

    public function __construct(string $message, int $status = 0, bool $uncertain = false, array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->uncertain = $uncertain;
        $this->messages = $errors;
    }

    public function httpStatus(): int { return $this->status; }
    public function isUncertain(): bool { return $this->uncertain; }
    public function errors(): array { return $this->messages; }
}

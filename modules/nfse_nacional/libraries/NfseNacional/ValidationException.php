<?php
declare(strict_types=1);
namespace NfseNacional;

final class ValidationException extends \RuntimeException
{
    public array $issues;
    public function __construct(array $issues)
    {
        $this->issues = array_values($issues);
        parent::__construct(implode(' ', $this->issues));
    }
}

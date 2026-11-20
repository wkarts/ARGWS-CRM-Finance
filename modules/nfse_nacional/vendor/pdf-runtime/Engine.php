<?php
declare(strict_types=1);

namespace NfseNacional\PdfVendor;

/** Restrict the vendored facade to the assets shipped with this module. */
final class Engine extends \NFSE_NACIONAL_PDF_TCPDF
{
    protected function fileAllowedPaths(): array
    {
        return [__DIR__, dirname(__DIR__, 2) . '/assets'];
    }

    protected function fileMarkupAllowedPaths(): array
    {
        return $this->fileAllowedPaths();
    }

    protected function fileOptions(): array
    {
        return [
            'allowedPaths' => $this->fileAllowedPaths(),
            'markupAllowedPaths' => $this->fileMarkupAllowedPaths(),
            'allowedHosts' => [],
            'maxRemoteSize' => 1024,
            'curlopts' => [],
        ];
    }
}

<?php
declare(strict_types=1);

namespace NfseNacional;

/** Resolves only credentials bound to the current issuer and authenticated company. */
final class CertificateResolver
{
    private $model;
    private $contextProvider;
    private $companyProvider;
    private string $source = 'none';
    private bool $localAvailable = false;
    private bool $localPriority = false;

    public function __construct($model, ?callable $contextProvider = null, ?callable $companyProvider = null)
    {
        $this->model = $model;
        $this->contextProvider = $contextProvider ?? static fn() => CompanyContext::current();
        $this->companyProvider = $companyProvider ?? static function (array $context) {
            // This is a new extension contract, not an undocumented native Perfex API.
            // A provider must authorize this staff/company and return null only if absent.
            return function_exists('hooks') ? hooks()->apply_filters('nfse_nacional_company_certificate', null, $context) : null;
        };
    }

    public function resolve(?string $expectedTaxId = null, ?string $environment = null): Certificate
    {
        $this->source = 'none';
        $settings = $this->model->settings();
        $environment = $environment ?? (string) ($settings['environment'] ?? '2');
        EnvironmentAuth::assertApiSupported($settings, $environment);
        $profile = EnvironmentAuth::profile($settings, $environment);
        $row = $this->model->settingsRow();
        $this->localAvailable = !empty($row['certificate_enc']);
        $this->localPriority = ($settings['use_local_certificate'] ?? '0') === '1';
        $expectedTaxId = Config::taxId($expectedTaxId ?? (string) ($settings['provider']['tax_id'] ?? ''));
        if (!Validator::taxId($expectedTaxId)) { throw new \RuntimeException('Configure um CPF/CNPJ válido para a empresa emissora.'); }
        $company = ($this->contextProvider)();
        if (!$company instanceof CompanyContext) { throw new \RuntimeException('O contexto da empresa emissora está inválido.'); }
        $company->assertIssuer($expectedTaxId);
        $context = $company->descriptor($expectedTaxId);
        if ($profile['certificate_source'] === 'environment') {
            $material = method_exists($this->model, 'authMaterial') ? $this->model->authMaterial() : [];
            $record = $material[$environment]['certificate'] ?? null;
            if (!is_array($record) || empty($record['pfx']) || !is_string($record['password'] ?? null)) {
                throw new \RuntimeException('Cadastre um certificado A1 específico para este ambiente ou selecione a origem automática.');
            }
            $bytes = base64_decode((string) $record['pfx'], true);
            if ($bytes === false || $bytes === '') { throw new \RuntimeException('O certificado A1 deste ambiente está inválido. Importe-o novamente.'); }
            $this->source = 'environment';
            return Certificate::fromPkcs12($bytes, $record['password'], $expectedTaxId);
        }
        if ($profile['certificate_source'] === 'automatic' && $this->localAvailable && (($settings['auth_unified'] ?? '0') === '1' || $this->localPriority)) {
            return $this->local($row, $expectedTaxId);
        }

        $this->source = 'company';
        try { $credentials = ($this->companyProvider)($context); }
        catch (\Throwable $error) { throw new \RuntimeException('Não foi possível utilizar o certificado da empresa. Verifique a autorização do usuário e a integração cadastrada.', 403, $error); }
        if ($credentials !== null) { return $this->fromProvider($credentials, $context); }
        $this->source = 'multiempresa';
        $credentials = $company->credentials($expectedTaxId);
        if ($credentials !== null) { return $this->fromProvider($credentials, $context); }
        // Only an absent source permits fallback. Exceptions and invalid credentials
        // propagate instead of bypassing a provider's authorization or identity checks.
        if ($profile['certificate_source'] === 'automatic' && $this->localAvailable) { return $this->local($row, $expectedTaxId); }
        $this->source = 'none';
        throw new \RuntimeException('Não há certificado A1 disponível para esta empresa. Cadastre o certificado da empresa ou um A1 local.');
    }

    /** Returns safe diagnostics only; private keys, PFX bytes and passwords never escape. */
    public function inspect(?string $expectedTaxId = null, ?string $environment = null): array
    {
        $metadata = []; $available = false; $message = '';
        try { $metadata = $this->resolve($expectedTaxId, $environment)->metadata(); $available = true; $message = $this->source === 'local' ? 'Certificado A1 local disponível.' : 'Certificado da empresa disponível.'; }
        catch (\Throwable $error) { $message = $error->getMessage(); }
        return ['available' => $available, 'source' => $this->source,
            'source_label' => ['none' => 'Indisponível', 'company' => 'Certificado da empresa', 'multiempresa' => 'Certificado da empresa', 'local' => 'Certificado local', 'environment' => 'A1 deste ambiente'][$this->source],
            'metadata' => $metadata, 'local_available' => $this->localAvailable,
            'local_priority' => $this->localPriority, 'message' => $message];
    }

    private function fromProvider($credentials, array $context): Certificate
    {
        if (!is_array($credentials)) { throw new \RuntimeException('A integração de certificado retornou dados inválidos.'); }
        foreach (['company_id', 'scope', 'tax_id'] as $key) {
            if (!isset($credentials[$key]) || !is_string($credentials[$key]) || !hash_equals($context[$key], $credentials[$key])) {
                throw new \RuntimeException('O certificado retornado pela integração não pertence ao contexto da empresa emissora.');
            }
        }
        if (!isset($credentials['pkcs12'], $credentials['password']) || !is_string($credentials['pkcs12']) || !is_string($credentials['password'])) {
            throw new \RuntimeException('A integração deve fornecer o certificado PKCS#12 em memória e sua senha.');
        }
        return Certificate::fromPkcs12($credentials['pkcs12'], $credentials['password'], $context['tax_id']);
    }

    private function local(array $row, string $expectedTaxId): Certificate
    {
        $this->source = 'local';
        try { $stored = json_decode($this->model->crypto()->decrypt($row['certificate_enc']), true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \RuntimeException('O certificado A1 local armazenado está inválido. Importe o arquivo novamente.', 0, $error); }
        if (!is_array($stored) || !isset($stored['pfx'], $stored['password']) || !is_string($stored['pfx']) || !is_string($stored['password'])) {
            throw new \RuntimeException('O certificado A1 local armazenado está inválido. Importe o arquivo novamente.');
        }
        $bytes = base64_decode($stored['pfx'], true);
        if ($bytes === false || $bytes === '') { throw new \RuntimeException('O certificado A1 local armazenado está inválido. Importe o arquivo novamente.'); }
        return Certificate::fromPkcs12($bytes, $stored['password'], $expectedTaxId);
    }
}

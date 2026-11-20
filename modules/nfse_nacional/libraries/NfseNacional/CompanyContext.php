<?php
declare(strict_types=1);

namespace NfseNacional;

/** Immutable, server-derived company and authenticated staff context. */
final class CompanyContext
{
    private array $data;
    private ?object $runtimeContext;
    private ?object $app;
    private ?array $actor = null;

    private function __construct(array $data, ?object $runtimeContext, ?object $app)
    {
        $this->data = $data;
        $this->runtimeContext = $runtimeContext;
        $this->app = $app;
    }

    public static function current(): self
    {
        $background = AutomationContext::current();
        if ($background !== null) {
            return new self(['company_id' => $background['company_id'], 'organization_id' => $background['organization_id'],
                'scope' => $background['scope'], 'prefix' => db_prefix(), 'runtime' => 'automation',
                'staff_id' => $background['staff_id'], 'user_id' => null], null, null);
        }
        if (!function_exists('nfse_nacional_scope') || !function_exists('db_prefix')
            || !function_exists('is_staff_logged_in') || !is_staff_logged_in()
            || !function_exists('get_staff_user_id') || (int) get_staff_user_id() < 1) {
            throw new \RuntimeException('A resolução do certificado exige uma sessão autenticada da empresa emissora.');
        }
        $scope = nfse_nacional_scope();
        $prefix = (string) db_prefix();
        if (!is_string($scope) || !preg_match('/^[a-f0-9]{64}$/D', $scope)
            || !preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new \RuntimeException('O contexto da empresa não pôde ser confirmado.');
        }
        $data = ['company_id' => 'standalone', 'organization_id' => '', 'scope' => $scope,
            'prefix' => $prefix, 'runtime' => 'native', 'staff_id' => (int) get_staff_user_id(), 'user_id' => null];
        $context = null; $app = null;
        if ((defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true)) {
            $context = $GLOBALS['pme_context'] ?? null;
            $app = $GLOBALS['pme_app'] ?? null;
            if (PME_CONTEXT_READY !== true || !$context instanceof \Multiempresa\Domain\Context
                || !$app instanceof \Multiempresa\Application\App || $context->prefix !== $prefix
                || !preg_match('/^[a-f0-9]{32}$/D', $context->organizationId)) {
                throw new \RuntimeException('O contexto autenticado da empresa está indisponível.');
            }
            $principal = $context->principal;
            if (!is_array($principal) || !preg_match('/^[a-f0-9]{32}$/D', (string) ($principal['user_id'] ?? ''))
                || (int) ($principal['staff_id'] ?? 0) !== $data['staff_id']
                || (isset($principal['company_id']) && $principal['company_id'] !== $context->companyId)) {
                throw new \RuntimeException('A sessão autenticada não corresponde à empresa emissora.');
            }
            $data = array_replace($data, ['company_id' => $context->companyId, 'organization_id' => $context->organizationId,
                'runtime' => 'multiempresa', 'user_id' => $principal['user_id']]);
        }
        $self = new self($data, $context, $app);
        if ($context !== null) { $self->actor = $self->authenticatedActor(); }
        return $self;
    }

    public function descriptor(string $expectedTaxId): array
    {
        return $this->data + ['tax_id' => $expectedTaxId];
    }

    /** A registered issuer must not be replaced by changing only module settings. */
    public function assertIssuer(string $expectedTaxId): void
    {
        if (!function_exists('get_option')) { return; }
        $registered = get_option('company_vat');
        if (!is_scalar($registered) || is_bool($registered)) { return; }
        $registered = strtoupper(preg_replace('/[.\/\-\s]+/', '', trim((string) $registered)) ?? '');
        if (Validator::taxId($registered) && !hash_equals($registered, $expectedTaxId)) {
            throw new \RuntimeException('O CPF/CNPJ do emitente não corresponde ao cadastro da empresa atual. Revise os dados da empresa antes de emitir.');
        }
    }

    /** Uses the supplied Multiempresa API; never reads its vault or certificate table. */
    public function credentials(string $expectedTaxId): ?array
    {
        if ($this->runtimeContext === null || $this->app === null) { return null; }
        $context = $this->runtimeContext;
        try {
            // credentials() itself requires CompanyManager::managed(). This existing
            // administrator restriction is intentionally preserved for delegated users.
            $credentials = (new \Multiempresa\Application\Certificates($this->app))->credentials($this->actor, $context->companyId);
            return ['company_id' => $context->companyId, 'scope' => $this->data['scope'], 'tax_id' => $expectedTaxId,
                'pkcs12' => $credentials['pkcs12'] ?? null, 'password' => $credentials['password'] ?? null];
        } catch (\Multiempresa\Domain\Failure $error) {
            if ($error->reason === 'certificate_missing') { return null; }
            if (in_array($error->reason, ['manager_required', 'platform_admin'], true)) {
                throw new \RuntimeException('Não foi possível utilizar o certificado da empresa. Verifique a autorização do seu usuário para acessar o certificado ou configure um A1 local da empresa.', 403, $error);
            }
            throw new \RuntimeException('Não foi possível confirmar o acesso ao certificado desta empresa. Verifique a autorização do usuário e o cadastro do certificado.', 403, $error);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Não foi possível acessar o certificado da empresa. Tente novamente ou solicite a conferência da integração.', 0, $error);
        }
    }

    private function authenticatedActor(): array
    {
        $context = $this->runtimeContext;
        try {
            $company = $this->app->catalog->company($context->companyId);
            if (($company['organization_id'] ?? '') !== $context->organizationId
                || ($company['table_prefix'] ?? '') !== $context->prefix
                || ($company['status'] ?? '') !== 'active' || empty($company['organization_active'])) {
                throw new \RuntimeException('A empresa atual está indisponível ou seu contexto cadastral mudou.');
            }
            $actor = $this->app->auth->refresh($context->principal['user_id']);
            if (isset($context->principal['auth_version'])
                && (int) $context->principal['auth_version'] !== (int) ($actor['auth_version'] ?? -1)) {
                throw new \RuntimeException('A identidade autenticada mudou. Entre novamente na empresa.');
            }
            $membership = $this->app->catalog->membership($actor['id'], $context->companyId);
            if ((int) ($membership['staff_id'] ?? 0) !== $this->data['staff_id']) {
                throw new \RuntimeException('O vínculo do funcionário com a empresa mudou. Entre novamente.');
            }
            return $actor;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Não foi possível confirmar o acesso à empresa emissora. Verifique seu vínculo e entre novamente.', 403, $error);
        }
    }
}
